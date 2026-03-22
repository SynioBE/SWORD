<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SSH\SSHService;
use Illuminate\Console\Command;

class DeployMuPluginCommand extends Command
{
    protected $signature = 'site:deploy-mu-plugin {site : Site ID}';

    protected $description = 'Deploy (or re-deploy) the SWORD magic-login MU-plugin to an existing site\'s container';

    public function handle(): int
    {
        $site = Site::find($this->argument('site'));

        if (! $site) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $this->info("Deploying MU-plugin to site #{$site->id} ({$site->domain})...");

        $encoded = base64_encode($this->pluginContent());
        $container = "sword_{$site->id}_php";

        // Write via base64 decode — avoids any heredoc/quoting issues over SSH.
        $command = implode(' && ', [
            "echo {$encoded} | base64 -d > /tmp/sword-auth.php",
            "docker exec {$container} mkdir -p /var/www/html/wp-content/mu-plugins",
            "docker cp /tmp/sword-auth.php {$container}:/var/www/html/wp-content/mu-plugins/sword-auth.php",
            'rm /tmp/sword-auth.php',
            'echo done',
        ]);

        $ssh = app(SSHService::class, ['server' => $site->server, 'timeout' => 30]);
        $result = $ssh->execute($command);

        if (! $result->isSuccessful()) {
            $this->error("SSH command failed (exit {$result->exitCode}):");
            $this->line($result->stderr);

            return self::FAILURE;
        }

        $this->info("MU-plugin deployed successfully to {$site->domain}.");

        return self::SUCCESS;
    }

    private function pluginContent(): string
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: SWORD Magic Login
 * Description: One-time magic-login token handler for the SWORD control panel.
 */
add_action('init', function () {
    if (empty($_GET['sword_magic'])) {
        return;
    }

    // Read and consume the token directly from wp_options via $wpdb,
    // bypassing the Redis object-cache drop-in entirely.
    global $wpdb;

    $token = sanitize_text_field(wp_unslash($_GET['sword_magic']));
    $key   = '_sword_token_' . $token;

    $user_id = $wpdb->get_var(
        $wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $key)
    );

    if (! $user_id) {
        wp_die('Magic login link has expired or is invalid.', 'Login Failed', ['response' => 403]);
    }

    $wpdb->delete($wpdb->options, ['option_name' => $key], ['%s']);

    wp_set_current_user((int) $user_id);
    wp_set_auth_cookie((int) $user_id, false);
    wp_redirect(admin_url());
    exit;
});
PHP;
    }
}

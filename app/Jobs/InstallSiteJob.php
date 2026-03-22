<?php

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SSH2;

class InstallSiteJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Site $site,
        public readonly string $wpAdminUser,
        public readonly string $wpAdminPassword,
        public readonly string $wpAdminEmail,
        public readonly string $wpAdminDisplayName,
    ) {}

    public function handle(): void
    {
        Log::info('Starting site installation', ['site_id' => $this->site->id]);
        $site = $this->site;
        $server = $site->server;

        Log::info('Connecting to server for site installation', ['server_id' => $server->id, 'ip_address' => $server->ip_address]);

        $installUrl = route('sites.scripts.install', [
            'site' => $site->id,
            'token' => $site->install_token,
            'wp_admin_user' => $this->wpAdminUser,
            'wp_admin_password' => $this->wpAdminPassword,
            'wp_admin_email' => $this->wpAdminEmail,
            'wp_admin_display_name' => $this->wpAdminDisplayName,
        ]);

        Log::info('Generated installation URL', ['install_url' => $installUrl]);

        $privateKey = EC::loadFormat('OpenSSH', $server->ssh_private_key);

        Log::info('Attempting SSH connection to server', ['server_id' => $server->id]);
        $ssh = new SSH2($server->ip_address, $server->ssh_port);
        $ssh->login('root', $privateKey);
        Log::info('SSH connection established, executing installation script', ['server_id' => $server->id]);
        $ssh->exec(sprintf('wget -qO create-wp-site.sh "%s" && nohup bash create-wp-site.sh > create-wp-site.log 2>&1 < /dev/null & disown', $installUrl));
        Log::info('Installation script executed via SSH', ['server_id' => $server->id]);
    }
}

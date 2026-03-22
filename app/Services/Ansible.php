<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class Ansible
{
    public function generateInventory()
    {
        // Get all servers.
        $formattedData = [
            'servers' => [
                'hosts' => [],
            ],
            'sites' => [
                'hosts' => [],
            ],
        ];
        // Structure the data.

        $servers = Server::all();
        foreach ($servers as $server) {
            $formattedData['servers']['hosts']['server-'.$server->id.'-'.$server->name] = [
                'ansible_host' => $server->ip_address,
                'ansible_user' => 'root', // @todo make this a variable.
                'ansible_port' => $server->ssh_port, // @todo make this a variable.
                'ansible_ssh_private_key_file' => $this->createTempPrivateKeyFile($server),
            ];
        }

        // get all sites.
        $sites = Site::all();

        foreach ($sites as $site) {
            $formattedData['sites']['hosts']['site-'.$site->id.'-'.$site->domain] = [
                'server_id' => $site->server_id,
                'domain' => $site->domain,
                'php_version' => $site->php_version,
                'db_name' => $site->db_name,
                'db_user' => $site->db_user,
                'db_password' => $site->db_password,
            ];
        }
        // Create Yaml Inventory file.
        $yaml = Yaml::dump(
            $formattedData,
            9001, //It's over 9000.
            4,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );
        file_put_contents('/tmp/ansible-sword-inventory.yml', $yaml);
    }

    /**
     * Create the private key file in the temp directory and return the path.
     *
     * @param  $server  The Server model instance.
     */
    public function createTempPrivateKeyFile($server): string
    {
        $private_key = $server->ssh_private_key;

        // Put the private key in a temporary file
        $tempKeyPath = tempnam(sys_get_temp_dir(), 'ssh_key_'.$server->name);
        file_put_contents($tempKeyPath, $private_key);
        chmod($tempKeyPath, 0600); // Set permissions to read/write for the owner only.

        return $tempKeyPath;
    }

    /**
     * Run a specific playbook.
     *
     * @param  int|Server  $server  The Server model, server ID, or null to run for all servers.
     * @param  string|null  $playbookpath  Path to a playbook, relative to ./ansible-playbooks.
     *                                     Or full paths. Default to main.yml
     * @param  array<string, mixed>  $extraVars  Extra variables to pass to the playbook via --extra-vars.
     */
    public function runServerPlaybook( int|Server $server, ?string $playbookpath = null, array $extraVars = [])
    {

        if (is_int($server)) {
            $server = Server::findOrFail($server);
        }
        if ($server) {
            $LimitServer = 'server-'.$server->id.'-'.$server->name;
        }

        // Ugly path handling.
        if (empty($playbookpath)) {
            $playbookpath = __DIR__.'/../../ansible-playbooks/provision.yml';
        } elseif (! str_starts_with($playbookpath, '/')) {
            $playbookpath = __DIR__.'/../../ansible-playbooks/'.$playbookpath;
        }

        $extraVars = array_merge(
            [
                'callback_url' => route('servers.callbacks.provision', [
                    'server' => $server->id,
                    'signature' => $server->callback_signature,
                ]),
                'server_name' => $server->name ?? null,
                'server_id' => $server->id ?? null,
                'server_hostname' => $server->hostname ?? null,
                'ssh_public_key' => $server->ssh_public_key ?? null,
                'timezone' => $server->timezone ?? null,
                'sudo_password' => $server->sudo_password ?? null,
                'mysql_root_password' => $server->mysql_root_password ?? null,
            ],
            $extraVars
        );

        $this->runPlaybook($playbookpath, $extraVars, $LimitServer);
    }

    public function runSitePlaybook( int|Site $site, ?string $playbookpath = null, array $extraVars = [])
    {
        if (is_int($site)) {
            $site = Site::findOrFail($site);
        }

        // Ugly path handling.
        if (empty($playbookpath)) {
            $playbookpath = __DIR__.'/../../ansible-playbooks/create-wp.yml';
        } elseif (! str_starts_with($playbookpath, '/')) {
            $playbookpath = __DIR__.'/../../ansible-playbooks/'.$playbookpath;
        }

        $server = $site->server;

        $extraVars = array_merge(
            [
                'callback_url' => route('sites.callbacks.install', [
                    'site' => $site->id,
                    'signature' => $site->callback_signature,
                ]),
                'domain' => $site->domain,
                'site_id' => $site->id,
                'php_version' => $site->php_version,
                'db_name' => $site->db_name,
                'db_user' => $site->db_user,
                'db_password' => $site->db_password,
                'mysql_root_password' => $server->mysql_root_password,
                // @todo these should be stored in the Job, and not generated here.
                'wp_admin_user' => $site->user->name ?? 'admin',
                'wp_admin_password' => Str::random(16), // @todo Get from the job.
                'admin_email' => $site->user->email ?? 'admin@'.$site->domain,
                'admin_display_name' => $site->user->name ?? null,
            ],
            $extraVars
        );

        return $this->runPlaybook( $playbookpath, $extraVars);

    }

    public function runPlaybook( string $playbookpath, array $extraVars = [], string $LimitServer = 'all')
    {
        // Always make sure we have a fresh inventory.
        $this->generateInventory();

        $command = 'ANSIBLE_HOST_KEY_CHECKING=false ';
        $command .= 'ansible-playbook';
        $command .= ' -i /tmp/ansible-sword-inventory.yml';
        $command .= " $playbookpath";
        $command .= " --limit $LimitServer";
        $command .= ' --extra-vars '.escapeshellarg(\json_encode($extraVars));

        // echo ">>Running command<<:\n$command\n";
        // die();

        $result = Process::run($command);

        if ($result->successful()) {
            logger()->info("Successfully ran Ansible playbook $playbookpath for server $LimitServer.");
        } else {
            logger()->error("Failed to run Ansible playbook $playbookpath for server $LimitServer. Error: ".$result->errorOutput());
        }

        logger()->debug("Ansible playbook output for server $LimitServer.", [
            'playbook' => "$playbookpath-$LimitServer",
            'output' => $result->output(),
        ]);

    }
}

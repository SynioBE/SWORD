<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

class Ansible
{
    public function GenerateInventory()
    {
        // Get all servers.
        $servers = Server::all();
        $formattedData = [
            'all' => [
                'hosts' => [],
            ],
        ];
        // Structure the data.

        foreach ($servers as $server) {
            $formattedData['all']['hosts']['server-'.$server->id.'-'.$server->name] = [
                'ansible_host' => $server->ip_address,
                'ansible_user' => 'root', // @todo make this a variable.
                'ansible_port' => $server->ssh_port, // @todo make this a variable.
                'ansible_ssh_private_key_file' => $this->createTempPrivateKeyFile($server),
            ];
        }
        // Create Yaml Inventory file.
        $yaml = Yaml::dump($formattedData, 2);
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
     * @param  mixed  $serverID  the server ID, or empty to run for all servers.
     * @param  string|null  $playbookpath  Path to a playbook, relative to ./ansible-playbooks.
     *                                     Or full paths. Default to main.yml
     */
    public function RunPlaybook($server = null, ?string $playbookpath = null)
    {
        // Always make sure we have a fresh inventory.
        $this->GenerateInventory();

        if(is_int($server)) {
            $server = Server::findOrFail($server);
        }
        $LimitServer = 'all';
        if($server) {
            $LimitServer = 'server-'.$server->id.'-'.$server->name;
        }

        if(empty($playbookpath)) {
            $playbookpath = __DIR__ .'/../../ansible-playbooks/main.yml';
        } elseif (!str_starts_with($playbookpath, '/')) {
            $playbookpath = __DIR__ .'/../../ansible-playbooks/'.$playbookpath;
        }

        $result = Process::run("ansible-playbook -i /tmp/ansible-sword-inventory.yml $playbookpath --limit $LimitServer");

        if ($result->successful()) {
            logger()->info("Successfully ran Ansible playbook $playbookpath for server $LimitServer.");
        } else {
            logger()->error("Failed to run Ansible playbook $playbookpath for server $LimitServer. Error: ".$result->errorOutput());
        }
        echo $result->output();
    }
}

<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Ansible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;

class RunAnsible implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $serverID
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $server = Server::findOrFail($this->serverID);

        $ansible = new Ansible;
        $ansible->RunPlaybook($this->serverID, 'provision.yml');

        // $this->testRawSSHConnection($server);
    }

    /**
     * Test the raw SSH connection
     *
     * @param  mixed  $server
     */
    protected function testRawSSHConnection($server)
    {
        $user = 'root'; // @todo make this a variable.
        $port = $server->ssh_port;
        $server_ip = $server->ip_address;
        $server_name = $server->name;
        $private_key = $server->ssh_private_key;

        // Put the private key in a temporary file
        $tempKeyPath = tempnam(sys_get_temp_dir(), 'ssh_key_'.$server_name);
        file_put_contents($tempKeyPath, $private_key);
        chmod($tempKeyPath, 0600); // Set permissions to read/write for the owner only

        // Validate if we can connect to the server with the provided credentials.
        $result = Process::run(
            "ssh $user@$server_ip-p $port -i $tempKeyPath -o \"StrictHostKeyChecking=no\" -- whoami"
        );
        if ($result->successful()) {
            logger()->info("Successfully connected to server $server_name ($server_ip) as $user.");
        } else {
            logger()->error("Failed to connect to server $server_name ($server_ip) as $user. Error: ".$result->errorOutput());
        }
        // remove the temporary key file
        unlink($tempKeyPath);
    }
}

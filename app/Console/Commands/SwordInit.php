<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;

class SwordInit extends Command
{
    protected $signature = 'sword:init
        {--admin-name= : Admin user name}
        {--admin-email= : Admin user email}
        {--admin-password= : Admin user password}
        {--server-ip= : Public IP address of this server}
        {--mysql-root-password= : MySQL root password}
        {--sudo-password= : Sudo password for the sword user}';

    protected $description = 'Initialize SWORD with an admin user and localhost server';

    public function handle(): int
    {
        $user = User::firstOrCreate(
            ['email' => $this->option('admin-email')],
            [
                'name' => $this->option('admin-name'),
                'password' => $this->option('admin-password'),
            ],
        );

        $this->info("Admin user ready: {$user->email}");

        $sshKeyPath = '/home/sword/.ssh/id_ed25519';

        if (! file_exists($sshKeyPath)) {
            $this->error("SSH key not found at {$sshKeyPath}");

            return self::FAILURE;
        }

        $privateKey = file_get_contents($sshKeyPath);
        $publicKey = file_get_contents("{$sshKeyPath}.pub");

        $server = Server::firstOrCreate(
            ['provider' => 'localhost'],
            [
                'user_id' => $user->id,
                'name' => 'Localhost',
                'ip_address' => $this->option('server-ip'),
                'hostname' => gethostname(),
                'timezone' => date_default_timezone_get(),
                'ssh_port' => 22,
                'ssh_private_key' => $privateKey,
                'ssh_public_key' => $publicKey,
                'mysql_root_password' => $this->option('mysql-root-password'),
                'sudo_password' => $this->option('sudo-password'),
                'status' => 'provisioned',
                'provisioned_at' => now(),
                'is_online' => true,
            ],
        );

        $this->info("Localhost server ready: {$server->ip_address} (ID: {$server->id})");

        return self::SUCCESS;
    }
}

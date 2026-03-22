<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use Illuminate\Console\Command;

class SwordInit extends Command
{
    protected $signature = 'sword:init {config-file : Path to JSON config file with init parameters}';

    protected $description = 'Initialize SWORD with an admin user and localhost server';

    public function handle(): int
    {
        $configPath = $this->argument('config-file');

        if (! file_exists($configPath)) {
            $this->error("Config file not found: {$configPath}");

            return self::FAILURE;
        }

        $config = json_decode(file_get_contents($configPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in config file: '.json_last_error_msg());

            return self::FAILURE;
        }

        $required = ['admin_name', 'admin_email', 'admin_password', 'server_ip', 'mysql_root_password', 'sudo_password', 'ssh_private_key', 'ssh_public_key'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                $this->error("Missing required config key: {$key}");

                return self::FAILURE;
            }
        }

        $user = User::firstOrCreate(
            ['email' => $config['admin_email']],
            [
                'name' => $config['admin_name'],
                'password' => $config['admin_password'],
            ],
        );

        $this->info("Admin user ready: {$user->email}");

        $server = Server::firstOrCreate(
            ['provider' => 'localhost'],
            [
                'user_id' => $user->id,
                'name' => 'Localhost',
                'ip_address' => $config['server_ip'],
                'hostname' => gethostname(),
                'timezone' => date_default_timezone_get(),
                'ssh_port' => 22,
                'ssh_private_key' => $config['ssh_private_key'],
                'ssh_public_key' => $config['ssh_public_key'],
                'mysql_root_password' => $config['mysql_root_password'],
                'sudo_password' => $config['sudo_password'],
                'status' => 'provisioned',
                'provisioned_at' => now(),
                'is_online' => true,
            ],
        );

        $this->info("Localhost server ready: {$server->ip_address} (ID: {$server->id})");

        return self::SUCCESS;
    }
}

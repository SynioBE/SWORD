<?php

namespace App\Services\Backup;

use App\Contracts\BackupDriver;
use App\Models\BackupDestination;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Services\SSH\SSHResult;
use App\Services\SSH\SSHService;

class BorgBackupDriver implements BackupDriver
{
    public function ensureInstalled(SSHService $ssh): void
    {
        $ssh->execute('which borg || sudo apt-get install -y borgbackup sshpass');
    }

    public function initializeRepo(SSHService $ssh, BackupDestination $destination, Server $server): SSHResult
    {
        $repo = $this->buildRepoPath($destination, $server);
        $env = $this->buildBorgEnv($destination);

        $command = $env['prefix']."borg init --encryption=none {$repo} 2>&1";

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    public function dumpDatabases(SSHService $ssh, Server $server): SSHResult
    {
        $password = str_replace("'", "'\\''", $server->mysql_root_password);
        $sites = $server->sites()->whereNotNull('db_name')->get();

        $commands = ['mkdir -p /srv/sword/backups/mysql'];

        foreach ($sites as $site) {
            $dbName = $site->db_name;
            $commands[] = "docker exec sword_mysql mysqldump -uroot -p'{$password}' --single-transaction --routines --triggers {$dbName} > /srv/sword/backups/mysql/{$dbName}.sql 2>&1";
        }

        return $ssh->execute(implode(' && ', $commands));
    }

    public function createBackup(SSHService $ssh, BackupSchedule $schedule): SSHResult
    {
        $destination = $schedule->backupDestination;
        $server = $schedule->server;
        $repo = $this->buildRepoPath($destination, $server);
        $env = $this->buildBorgEnv($destination);

        $archiveName = $server->hostname.'-'.now()->format('Y-m-d\TH:i');

        // Exclude raw MySQL data files since we dump databases separately
        $command = $env['prefix']."borg create --stats --compression auto,zstd --exclude '/srv/sword/shared/mysql/data' {$repo}::{$archiveName} /srv/sword 2>&1";

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    public function prune(SSHService $ssh, BackupSchedule $schedule): SSHResult
    {
        $destination = $schedule->backupDestination;
        $server = $schedule->server;
        $repo = $this->buildRepoPath($destination, $server);
        $env = $this->buildBorgEnv($destination);

        $keepLast = $schedule->retention_count;

        $command = $env['prefix']."borg prune --keep-last={$keepLast} --stats {$repo} 2>&1 && "
            .$env['prefix']."borg compact {$repo} 2>&1";

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    public function buildRepoPath(BackupDestination $destination, Server $server): string
    {
        $storagePath = rtrim($destination->storage_path, '/');

        return "ssh://{$destination->username}@{$destination->host}:{$destination->port}{$storagePath}/{$server->hostname}";
    }

    /**
     * @return array{prefix: string, cleanup: string|null}
     */
    public function buildBorgEnv(BackupDestination $destination): array
    {
        $cleanup = null;

        if ($destination->auth_method === 'ssh_key') {
            $keyPath = '/tmp/sword_borg_key_'.bin2hex(random_bytes(4));
            $cleanup = "rm -f {$keyPath}";

            $escapedKey = str_replace("'", "'\\''", $destination->ssh_private_key);
            $prefix = "echo '{$escapedKey}' > {$keyPath} && chmod 600 {$keyPath} && "
                ."BORG_RSH=\"ssh -i {$keyPath} -p {$destination->port} -o StrictHostKeyChecking=accept-new\" ";
        } else {
            $escapedPassword = str_replace("'", "'\\''", $destination->password);
            $prefix = "BORG_RSH=\"sshpass -p '{$escapedPassword}' ssh -p {$destination->port} -o StrictHostKeyChecking=accept-new\" ";
        }

        return [
            'prefix' => $prefix,
            'cleanup' => $cleanup,
        ];
    }
}

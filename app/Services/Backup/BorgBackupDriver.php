<?php

namespace App\Services\Backup;

use App\Contracts\BackupDriver;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\Site;
use App\Services\SSH\SSHResult;
use App\Services\SSH\SSHService;

class BorgBackupDriver implements BackupDriver
{
    public function ensureInstalled(SSHService $ssh): void
    {
        $result = $ssh->execute('which borg || sudo apt-get install -y borgbackup sshpass');

        if ($result->exitCode !== 0) {
            throw new \RuntimeException("Failed to install borgbackup: {$result->stderr}");
        }
    }

    public function setup(SSHService $ssh, BackupDestination $destination, Server $server, Site $site): SSHResult
    {
        $repo = $this->buildRepoPath($destination, $server, $site->domain);
        $env = $this->buildBorgEnv($destination);

        // Ensure the parent directory exists on the destination
        $storagePath = rtrim($destination->storage_path, '/');
        $parentPath = "{$storagePath}/sites/{$server->hostname}";
        $mkdirCommand = $this->buildRemoteCommand($destination, "mkdir -p ".escapeshellarg($parentPath));
        $ssh->execute($mkdirCommand);

        $command = $env['prefix'].'borg init --encryption=none '.escapeshellarg($repo).' 2>&1';

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    /**
     * Build a command that executes on the backup destination via SSH.
     */
    private function buildRemoteCommand(BackupDestination $destination, string $remoteCommand): string
    {
        $port = (int) $destination->port;
        $host = escapeshellarg("{$destination->username}@{$destination->host}");

        if ($destination->auth_method === 'ssh_key') {
            $keyPath = '/tmp/sword_mkdir_key_'.bin2hex(random_bytes(4));
            $escapedKey = str_replace("'", "'\\''", $destination->ssh_private_key);

            return "echo '{$escapedKey}' > {$keyPath} && chmod 600 {$keyPath} && "
                ."ssh -i {$keyPath} -p {$port} -o StrictHostKeyChecking=accept-new {$host} ".escapeshellarg($remoteCommand)
                ." 2>&1; rm -f {$keyPath}";
        }

        $escapedPassword = str_replace("'", "'\\''", $destination->password);

        return "sshpass -p '{$escapedPassword}' ssh -p {$port} -o StrictHostKeyChecking=accept-new {$host} ".escapeshellarg($remoteCommand).' 2>&1';
    }

    public function dumpDatabase(SSHService $ssh, Server $server, Site $site): SSHResult
    {
        $password = str_replace("'", "'\\''", $server->mysql_root_password);
        $dbName = escapeshellarg($site->db_name);
        $dumpDir = '/srv/sword/backups/mysql';

        $command = "mkdir -p {$dumpDir} && "
            ."docker exec sword_mysql mysqldump -uroot -p'{$password}' "
            ."--single-transaction --routines --triggers {$dbName} "
            ."> {$dumpDir}/{$site->db_name}.sql 2>/dev/null";

        return $ssh->execute($command);
    }

    public function backup(SSHService $ssh, BackupSchedule $schedule, Site $site): SSHResult
    {
        $destination = $schedule->backupDestination;
        $server = $schedule->server;
        $repo = $this->buildRepoPath($destination, $server, $site->domain);
        $env = $this->buildBorgEnv($destination);

        $archiveName = $site->domain.'-'.now()->format('Y-m-d\TH:i:s');
        $repoArchive = escapeshellarg($repo.'::'.$archiveName);

        $paths = implode(' ', [
            escapeshellarg("/srv/sword/sites/{$site->domain}"),
            escapeshellarg("/srv/sword/stacks/{$site->domain}"),
            escapeshellarg("/srv/sword/backups/mysql/{$site->db_name}.sql"),
        ]);

        $command = $env['prefix'].'borg create --stats --compression auto,zstd '.$repoArchive.' '.$paths.' 2>&1';

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    public function prune(SSHService $ssh, BackupSchedule $schedule, Site $site): SSHResult
    {
        $destination = $schedule->backupDestination;
        $server = $schedule->server;
        $repo = $this->buildRepoPath($destination, $server, $site->domain);
        $env = $this->buildBorgEnv($destination);

        $keepLast = (int) $schedule->retention_count;
        $escapedRepo = escapeshellarg($repo);

        $command = $env['prefix']."borg prune --keep-last={$keepLast} --stats {$escapedRepo} 2>&1 && "
            .$env['prefix']."borg compact {$escapedRepo} 2>&1";

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    public function restore(SSHService $ssh, SSHService $rootSsh, BackupRun $backupRun, Site $site): void
    {
        $destination = $backupRun->backupDestination;
        $server = $site->server;
        $domain = $site->domain;

        $repo = $this->buildRepoPath($destination, $server, $domain);
        $env = $this->buildBorgEnv($destination);
        $archive = $backupRun->archive_name;

        // 1. Extract archive to a temp directory (as sword — already knows the repo)
        $tempDir = '/srv/sword/restore_tmp_'.bin2hex(random_bytes(4));
        $repoArchive = escapeshellarg($repo.'::'.$archive);

        $extractCmd = "mkdir -p {$tempDir} && cd {$tempDir} && "
            .$env['prefix']."borg extract {$repoArchive} 2>&1";

        $result = $ssh->execute($extractCmd);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        if ($result->exitCode >= 2) {
            $ssh->execute("rm -rf {$tempDir}");
            throw new \RuntimeException("Borg extract failed: {$result->output} {$result->stderr}");
        }

        // 2. Replace site files (as root)
        $rootSsh->execute("rm -rf /srv/sword/sites/{$domain} && mv {$tempDir}/srv/sword/sites/{$domain} /srv/sword/sites/{$domain} 2>&1");
        $rootSsh->execute("rm -rf /srv/sword/stacks/{$domain} && mv {$tempDir}/srv/sword/stacks/{$domain} /srv/sword/stacks/{$domain} 2>&1");

        // 3. Fix permissions (as root)
        $rootSsh->execute("chown -R sword:sword /srv/sword/sites/{$domain} /srv/sword/stacks/{$domain} 2>&1");

        // 4. Import database (as root — needs docker access)
        $password = str_replace("'", "'\\''", $server->mysql_root_password);
        $dbName = $site->db_name;
        $sqlFile = "{$tempDir}/srv/sword/backups/mysql/{$dbName}.sql";

        $rootSsh->execute("docker exec -i sword_mysql mysql -uroot -p'{$password}' {$dbName} < {$sqlFile} 2>&1");

        // 5. Restart site containers (as root)
        $rootSsh->execute("docker compose -f /srv/sword/stacks/{$domain}/docker-compose.yml restart 2>&1");

        // 6. Cleanup temp directory (as root — may contain root-owned files)
        $rootSsh->execute("rm -rf {$tempDir}");
    }

    public function cleanup(SSHService $ssh, BackupDestination $destination, Server $server, string $domain): SSHResult
    {
        $repo = $this->buildRepoPath($destination, $server, $domain);
        $env = $this->buildBorgEnv($destination);

        $command = $env['prefix'].'borg delete --force '.escapeshellarg($repo).' 2>&1';

        $result = $ssh->execute($command);

        if ($env['cleanup']) {
            $ssh->execute($env['cleanup']);
        }

        return $result;
    }

    public function buildRepoPath(BackupDestination $destination, Server $server, string $domain): string
    {
        $storagePath = rtrim($destination->storage_path, '/');
        $username = $destination->username;
        $host = $destination->host;
        $port = (int) $destination->port;
        $hostname = $server->hostname;

        return "ssh://{$username}@{$host}:{$port}{$storagePath}/sites/{$hostname}/{$domain}";
    }

    /**
     * @return array{prefix: string, cleanup: string|null}
     */
    public function buildBorgEnv(BackupDestination $destination): array
    {
        $cleanup = null;
        $port = (int) $destination->port;

        if ($destination->auth_method === 'ssh_key') {
            $keyPath = '/tmp/sword_borg_key_'.bin2hex(random_bytes(4));
            $escapedKeyPath = escapeshellarg($keyPath);
            $cleanup = "rm -f {$escapedKeyPath}";

            $escapedKey = str_replace("'", "'\\''", $destination->ssh_private_key);
            $prefix = "echo '{$escapedKey}' > {$escapedKeyPath} && chmod 600 {$escapedKeyPath} && "
                ."BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=yes "
                ."BORG_RSH=\"ssh -i {$escapedKeyPath} -p {$port} -o StrictHostKeyChecking=accept-new\" ";
        } else {
            $escapedPassword = str_replace("'", "'\\''", $destination->password);
            $prefix = "BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=yes "
                ."BORG_RSH=\"sshpass -p '{$escapedPassword}' ssh -p {$port} -o StrictHostKeyChecking=accept-new\" ";
        }

        return [
            'prefix' => $prefix,
            'cleanup' => $cleanup,
        ];
    }
}

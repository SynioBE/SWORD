<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Models\Site;
use App\Services\Backup\BackupDriverManager;
use App\Services\SSH\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RestoreSiteBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public BackupRun $backupRun,
        public Site $site,
    ) {}

    public function handle(BackupDriverManager $manager): void
    {
        $run = $this->backupRun->loadMissing('backupDestination');
        $site = $this->site->loadMissing('server');

        $driver = $manager->driver($run->backupDestination->type);

        $ssh = new SSHService($site->server, $this->timeout);
        $rootSsh = new SSHService($site->server, $this->timeout, 'root');

        try {
            $ssh->connect();
            $rootSsh->connect();

            $driver->ensureInstalled($ssh);
            $driver->restore($ssh, $rootSsh, $run, $site);
        } finally {
            $ssh->disconnect();
            $rootSsh->disconnect();
        }
    }
}

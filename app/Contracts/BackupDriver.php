<?php

namespace App\Contracts;

use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\Site;
use App\Services\SSH\SSHResult;
use App\Services\SSH\SSHService;

interface BackupDriver
{
    /** Install the backup tool on the server if not present. */
    public function ensureInstalled(SSHService $ssh): void;

    /** One-time setup for a site's backup location (idempotent). */
    public function setup(SSHService $ssh, BackupDestination $destination, Server $server, Site $site): SSHResult;

    /** Dump the site's database to a local file on the server. */
    public function dumpDatabase(SSHService $ssh, Server $server, Site $site): SSHResult;

    /** Create a backup of the site. */
    public function backup(SSHService $ssh, BackupSchedule $schedule, Site $site): SSHResult;

    /** Remove old backups according to retention policy. */
    public function prune(SSHService $ssh, BackupSchedule $schedule, Site $site): SSHResult;

    /** Restore a site from a specific backup run. $ssh for extraction (as sword), $rootSsh for file placement (as root). */
    public function restore(SSHService $ssh, SSHService $rootSsh, BackupRun $backupRun, Site $site): void;

    /** Delete all backups for a site from the destination. */
    public function cleanup(SSHService $ssh, BackupDestination $destination, Server $server, string $domain): SSHResult;
}

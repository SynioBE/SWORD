<?php

namespace App\Contracts;

use App\Models\BackupDestination;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Services\SSH\SSHResult;
use App\Services\SSH\SSHService;

interface BackupDriver
{
    public function ensureInstalled(SSHService $ssh): void;

    public function dumpDatabases(SSHService $ssh, Server $server): SSHResult;

    public function initializeRepo(SSHService $ssh, BackupDestination $destination, Server $server): SSHResult;

    public function createBackup(SSHService $ssh, BackupSchedule $schedule): SSHResult;

    public function prune(SSHService $ssh, BackupSchedule $schedule): SSHResult;
}

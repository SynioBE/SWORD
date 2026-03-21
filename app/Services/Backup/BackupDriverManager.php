<?php

namespace App\Services\Backup;

use App\Contracts\BackupDriver;
use InvalidArgumentException;

class BackupDriverManager
{
    public function driver(string $type): BackupDriver
    {
        return match ($type) {
            'borg' => new BorgBackupDriver,
            default => throw new InvalidArgumentException("Unknown backup driver: {$type}"),
        };
    }
}

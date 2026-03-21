<?php

namespace App\Models;

use Database\Factories\BackupScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'server_id',
    'backup_destination_id',
    'frequency',
    'time',
    'day_of_week',
    'day_of_month',
    'retention_count',
    'is_enabled',
])]
class BackupSchedule extends Model
{
    /** @use HasFactory<BackupScheduleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'retention_count' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backupDestination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class);
    }

    /** @return HasMany<BackupRun, $this> */
    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    /** @return HasOne<BackupRun, $this> */
    public function latestBackupRun(): HasOne
    {
        return $this->hasOne(BackupRun::class)->latestOfMany();
    }
}

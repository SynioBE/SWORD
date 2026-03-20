<?php

namespace App\Models;

use Database\Factories\BackupDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'type',
    'host',
    'port',
    'username',
    'auth_method',
    'password',
    'ssh_private_key',
    'storage_path',
    'status',
    'last_connected_at',
])]
class BackupDestination extends Model
{
    /** @use HasFactory<BackupDestinationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'ssh_private_key' => 'encrypted',
            'last_connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BackupSchedule, $this> */
    public function backupSchedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class);
    }
}

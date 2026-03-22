<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;

#[Fillable([
    'user_id',
    'integration_id',
    'name',
    'ip_address',
    'hostname',
    'timezone',
    'region',
    'provider',
    'server_type',
    'image',
    'ssh_port',
    'sudo_password',
    'mysql_root_password',
    'ssh_public_key',
    'ssh_private_key',
    'provision_token',
    'callback_signature',
    'status',
    'current_step',
    'provision_log',
    'provisioned_at',
    'is_online',
    'last_pinged_at',
])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provision_log' => 'array',
            'provisioned_at' => 'datetime',
            'ssh_port' => 'integer',
            'ssh_public_key' => 'encrypted',
            'ssh_private_key' => 'encrypted',
            'sudo_password' => 'encrypted',
            'mysql_root_password' => 'encrypted',
            'is_online' => 'boolean',
            'last_pinged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Server $server): void {
            if (empty($server->provision_token)) {
                $server->provision_token = Str::random(64);
            }

            if (empty($server->callback_signature)) {
                $server->callback_signature = hash('sha256', Str::random(40));
            }

            if (empty($server->ssh_private_key)) {
                $privateKey = EC::createKey('Ed25519');
                $server->ssh_private_key = $privateKey->toString('OpenSSH');
                $server->ssh_public_key = $privateKey->getPublicKey()->toString('OpenSSH', ['comment' => 'sword-'.Str::random(8)]);
            }

            if (empty($server->sudo_password)) {
                $server->sudo_password = Str::password(32, symbols: false);
            }

            if (empty($server->mysql_root_password)) {
                $server->mysql_root_password = Str::password(32, symbols: false);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<BackupSchedule, $this> */
    public function backupSchedules(): HasMany
    {
        return $this->hasMany(BackupSchedule::class);
    }

    /** @return HasMany<BackupRun, $this> */
    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function isProvisioning(): bool
    {
        return $this->status === 'provisioning';
    }

    public function isProvisioned(): bool
    {
        return $this->status === 'provisioned';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}

<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'ip_address',
        'hostname',
        'timezone',
        'region',
        'provider',
        'ssh_port',
        'ssh_public_key',
        'ssh_private_key',
        'provision_token',
        'callback_signature',
        'status',
        'current_step',
        'provision_log',
        'provisioned_at',
    ];

    protected function casts(): array
    {
        return [
            'provision_log' => 'array',
            'provisioned_at' => 'datetime',
            'ssh_port' => 'integer',
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
                $server->ssh_public_key = $privateKey->getPublicKey()->toString('OpenSSH', ['comment' => 'sword']);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

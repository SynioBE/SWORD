<?php

namespace App\Models;

use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'server_id',
    'site_label',
    'user_id',
    'domain',
    'php_version',
    'db_name',
    'db_user',
    'db_password',
    'install_token',
    'callback_signature',
    'status',
    'current_step',
    'install_log',
    'installed_at',
])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'install_log' => 'array',
            'installed_at' => 'datetime',
            'db_password' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            if (empty($site->install_token)) {
                $site->install_token = Str::random(64);
            }

            if (empty($site->callback_signature)) {
                $site->callback_signature = hash('sha256', Str::random(40));
            }

            if (empty($site->db_password)) {
                $site->db_password = Str::password(32, symbols: false);
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<BackupRun, $this> */
    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInstalling(): bool
    {
        return $this->status === 'installing';
    }

    public function isInstalled(): bool
    {
        return $this->status === 'installed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}

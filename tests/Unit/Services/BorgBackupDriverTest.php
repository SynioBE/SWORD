<?php

use App\Models\BackupDestination;
use App\Models\Server;
use App\Services\Backup\BorgBackupDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class);

test('builds correct repo path', function () {
    $driver = new BorgBackupDriver;

    $destination = BackupDestination::factory()->make([
        'username' => 'backupuser',
        'host' => 'backup.example.com',
        'port' => 22,
        'storage_path' => '/backups',
    ]);

    $server = Server::factory()->make([
        'hostname' => 'my-server',
    ]);

    $path = $driver->buildRepoPath($destination, $server, 'example.com');

    expect($path)->toBe('ssh://backupuser@backup.example.com:22/backups/sites/my-server/example.com');
});

test('builds correct repo path with trailing slash in storage path', function () {
    $driver = new BorgBackupDriver;

    $destination = BackupDestination::factory()->make([
        'username' => 'user',
        'host' => 'host.com',
        'port' => 2222,
        'storage_path' => '/data/backups/',
    ]);

    $server = Server::factory()->make([
        'hostname' => 'web-01',
    ]);

    $path = $driver->buildRepoPath($destination, $server, 'mysite.org');

    expect($path)->toBe('ssh://user@host.com:2222/data/backups/sites/web-01/mysite.org');
});

test('builds password-based borg env', function () {
    $driver = new BorgBackupDriver;

    $destination = BackupDestination::factory()->make([
        'auth_method' => 'password',
        'password' => 'secret123',
        'port' => 22,
    ]);

    $env = $driver->buildBorgEnv($destination);

    expect($env['prefix'])->toContain('sshpass');
    expect($env['prefix'])->toContain('secret123');
    expect($env['prefix'])->toContain('-p 22');
    expect($env['cleanup'])->toBeNull();
});

test('builds ssh-key-based borg env', function () {
    $driver = new BorgBackupDriver;

    $destination = BackupDestination::factory()->sshKey()->make([
        'port' => 2222,
    ]);

    $env = $driver->buildBorgEnv($destination);

    expect($env['prefix'])->toContain('sword_borg_key');
    expect($env['prefix'])->toContain('chmod 600');
    expect($env['prefix'])->toContain('-p 2222');
    expect($env['cleanup'])->toContain('rm -f');
});

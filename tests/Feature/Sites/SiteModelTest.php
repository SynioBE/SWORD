<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;

test('install_token is auto-generated on creation', function () {
    $site = Site::factory()->create(['install_token' => null]);

    expect($site->install_token)->toBeString()->toHaveLength(64);
});

test('callback_signature is auto-generated on creation', function () {
    $site = Site::factory()->create(['callback_signature' => null]);

    expect($site->callback_signature)->toBeString()->toHaveLength(64);
});

test('db_password is auto-generated when not provided', function () {
    $site = Site::factory()->create(['db_password' => null]);

    expect($site->db_password)->toBeString()->not->toBeEmpty();
});

test('custom install_token is preserved', function () {
    $token = str_repeat('a', 64);
    $site = Site::factory()->create(['install_token' => $token]);

    expect($site->install_token)->toBe($token);
});

test('site belongs to a user', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create(['user_id' => $user->id]);

    expect($site->user)->toBeInstanceOf(User::class)
        ->and($site->user->id)->toBe($user->id);
});

test('site belongs to a server', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    expect($site->server)->toBeInstanceOf(Server::class)
        ->and($site->server->id)->toBe($server->id);
});

test('isPending returns true when status is pending', function () {
    $site = Site::factory()->create(['status' => 'pending']);

    expect($site->isPending())->toBeTrue()
        ->and($site->isInstalling())->toBeFalse()
        ->and($site->isInstalled())->toBeFalse()
        ->and($site->isFailed())->toBeFalse();
});

test('isInstalling returns true when status is installing', function () {
    $site = Site::factory()->installing()->create();

    expect($site->isInstalling())->toBeTrue()
        ->and($site->isPending())->toBeFalse();
});

test('isInstalled returns true when status is installed', function () {
    $site = Site::factory()->installed()->create();

    expect($site->isInstalled())->toBeTrue()
        ->and($site->installed_at)->not->toBeNull();
});

test('isFailed returns true when status is failed', function () {
    $site = Site::factory()->failed()->create();

    expect($site->isFailed())->toBeTrue();
});

test('install_log is cast to array', function () {
    $log = [['step' => 'nginx_setup', 'status' => 'ok']];
    $site = Site::factory()->create(['install_log' => $log]);

    expect($site->install_log)->toBeArray()
        ->and($site->install_log[0]['step'])->toBe('nginx_setup');
});

test('installed_at is cast to datetime', function () {
    $site = Site::factory()->installed()->create();

    expect($site->installed_at)->toBeInstanceOf(CarbonImmutable::class);
});

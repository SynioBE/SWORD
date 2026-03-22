<?php

use App\Models\Site;
use App\Models\User;
use App\Services\SSH\SSHResult;
use App\Services\SSH\SSHService;

test('guests cannot access the magic login endpoint', function () {
    $site = Site::factory()->installed()->create();

    $this->get(route('sites.magic-login', $site))
        ->assertRedirect(route('login'));
});

test('users cannot magic-login to a site belonging to another user', function () {
    $owner = User::factory()->create();
    $site = Site::factory()->installed()->create(['user_id' => $owner->id]);

    $this->actingAs(User::factory()->create())
        ->get(route('sites.magic-login', $site))
        ->assertForbidden();
});

test('magic login is rejected for a non-installed site', function () {
    $user = User::factory()->create();
    $site = Site::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    $this->actingAs($user)
        ->get(route('sites.magic-login', $site))
        ->assertStatus(422);
});

test('magic login returns an error when ssh command fails', function () {
    $user = User::factory()->create();
    $site = Site::factory()->installed()->create(['user_id' => $user->id]);

    $mockSsh = Mockery::mock(SSHService::class);
    $mockSsh->shouldReceive('execute')
        ->once()
        ->andReturn(new SSHResult(output: '', stderr: 'connection refused', exitCode: 1));

    $this->app->bind(SSHService::class, fn () => $mockSsh);

    $this->actingAs($user)
        ->get(route('sites.magic-login', $site))
        ->assertRedirect()
        ->assertSessionHasErrors('magic_login');
});

test('magic login redirects to the url returned by wp eval', function () {
    $user = User::factory()->create();
    $site = Site::factory()->installed()->create(['user_id' => $user->id]);

    $magicUrl = "https://{$site->domain}/?sword_magic=abc123";

    $mockSsh = Mockery::mock(SSHService::class);
    $mockSsh->shouldReceive('execute')
        ->once()
        ->andReturn(new SSHResult(output: $magicUrl, stderr: '', exitCode: 0));

    $this->app->bind(SSHService::class, fn () => $mockSsh);

    $this->actingAs($user)
        ->get(route('sites.magic-login', $site))
        ->assertRedirect($magicUrl);
});

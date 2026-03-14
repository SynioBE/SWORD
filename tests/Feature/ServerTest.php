<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Index ────────────────────────────────────────────────────

it('shows servers index to authenticated user', function () {
    $user = User::factory()->create();
    Server::factory()->count(3)->for($user)->create();

    $this->actingAs($user)
        ->get('/servers')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
                ->component('servers/Index')
                ->has('servers', 3)
        );
});

it('redirects guests from servers index', function () {
    $this->get('/servers')->assertRedirect('/login');
});

it('does not show other users servers', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Server::factory()->count(2)->for($other)->create();

    $this->actingAs($user)
        ->get('/servers')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page->has('servers', 0)
        );
});

// ── Store ────────────────────────────────────────────────────

it('can create a server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/servers', [
            'name' => 'My Server',
            'ip_address' => '65.21.100.42',
            'provider' => 'hetzner',
            'region' => 'eu-central',
            'timezone' => 'UTC',
            'ssh_port' => 22,
        ])
        ->assertRedirect();

    expect(Server::where('name', 'My Server')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('validates required fields when creating a server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/servers', [])
        ->assertInvalid(['name']);
});

it('validates timezone when creating a server', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/servers', [
            'name' => 'Test',
            'timezone' => 'Invalid/Timezone',
            'ssh_port' => 22,
        ])
        ->assertInvalid(['timezone']);
});

it('auto-generates provision_token and callback_signature', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/servers', [
        'name' => 'Token Test',
        'ip_address' => '1.2.3.4',
        'timezone' => 'UTC',
        'ssh_port' => 22,
    ]);

    $server = Server::where('user_id', $user->id)->first();

    expect($server->provision_token)->toHaveLength(64);
    expect($server->callback_signature)->toHaveLength(64);
});

it('auto-generates an ed25519 ssh key pair on create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/servers', [
        'name' => 'Key Test',
        'ip_address' => '1.2.3.5',
        'timezone' => 'UTC',
        'ssh_port' => 22,
    ]);

    $server = Server::where('user_id', $user->id)->first();

    expect($server->ssh_public_key)->toStartWith('ssh-ed25519 ');
    expect($server->ssh_private_key)->toContain('OPENSSH PRIVATE KEY');
});

// ── Show ─────────────────────────────────────────────────────

it('shows server detail page', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create();

    $this->actingAs($user)
        ->get("/servers/{$server->id}")
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
                ->component('servers/Show')
                ->has('server')
                ->where('server.id', $server->id)
                ->has('server.wget_command')
        );
});

it('forbids viewing another users server', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for(User::factory())->create();

    $this->actingAs($user)
        ->get("/servers/{$server->id}")
        ->assertForbidden();
});

// ── Provision script ─────────────────────────────────────────

it('serves the bash provision script with valid token', function () {
    $server = Server::factory()->for(User::factory())->create();

    $this->get("/servers/{$server->id}/provision-script?token={$server->provision_token}")
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=UTF-8');
});

it('rejects provision script with invalid token', function () {
    $server = Server::factory()->for(User::factory())->create();

    $this->get("/servers/{$server->id}/provision-script?token=bad-token")
        ->assertForbidden();
});

// ── Provision callback ───────────────────────────────────────

it('updates server status via provision callback', function () {
    $server = Server::factory()->for(User::factory())->create(['status' => 'pending']);

    $this->post("/callback/servers/{$server->id}/provision?signature={$server->callback_signature}", [
        'status' => 'provisioning',
        'step' => 'Installing Docker',
    ])->assertSuccessful();

    $server->refresh();
    expect($server->status)->toBe('provisioning');
    expect($server->current_step)->toBe('Installing Docker');
    expect($server->provision_log)->toHaveCount(1);
    expect($server->provision_log[0]['step'])->toBe('Installing Docker');
});

it('marks server as provisioned on final callback', function () {
    $server = Server::factory()->for(User::factory())->provisioning()->create();

    $this->post("/callback/servers/{$server->id}/provision?signature={$server->callback_signature}", [
        'status' => 'provisioned',
        'step' => 'Server provisioning complete',
    ])->assertSuccessful();

    $server->refresh();
    expect($server->status)->toBe('provisioned');
    expect($server->provisioned_at)->not->toBeNull();
    expect($server->current_step)->toBeNull();
});

it('rejects provision callback with invalid signature', function () {
    $server = Server::factory()->for(User::factory())->create();

    $this->post("/callback/servers/{$server->id}/provision?signature=invalid")
        ->assertForbidden();
});

it('accumulates provision log entries across multiple callbacks', function () {
    $server = Server::factory()->for(User::factory())->create();

    $this->post("/callback/servers/{$server->id}/provision?signature={$server->callback_signature}", [
        'status' => 'provisioning',
        'step' => 'Configuring swap',
    ]);

    $this->post("/callback/servers/{$server->id}/provision?signature={$server->callback_signature}", [
        'status' => 'provisioning',
        'step' => 'Installing Docker',
    ]);

    $server->refresh();
    expect($server->provision_log)->toHaveCount(2);
});

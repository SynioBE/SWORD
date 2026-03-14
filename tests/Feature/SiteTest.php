<?php

use App\Jobs\InstallSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ── Index ────────────────────────────────────────────────────

it('shows sites index to authenticated user', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->provisioned()->create();
    Site::factory()->count(3)->for($user)->for($server)->create();

    $this->actingAs($user)
        ->get('/sites')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
                ->component('sites/Index')
                ->has('sites', 3)
        );
});

it('redirects guests from sites index', function () {
    $this->get('/sites')->assertRedirect('/login');
});

it('does not show other users sites', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $server = Server::factory()->for($other)->provisioned()->create();
    Site::factory()->count(2)->for($other)->for($server)->create();

    $this->actingAs($user)
        ->get('/sites')
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page->has('sites', 0)
        );
});

it('returns only provisioned servers for site creation', function () {
    $user = User::factory()->create();
    Server::factory()->for($user)->provisioned()->create(['name' => 'Ready']);
    Server::factory()->for($user)->create(['name' => 'Pending', 'status' => 'pending']);

    $this->actingAs($user)
        ->get('/sites')
        ->assertInertia(
            fn ($page) => $page
                ->has('servers', 1)
                ->where('servers.0.name', 'Ready')
        );
});

// ── Store ────────────────────────────────────────────────────

it('can create a site', function () {
    Queue::fake();

    $user = User::factory()->create();
    $server = Server::factory()->for($user)->provisioned()->create();

    $this->actingAs($user)
        ->post('/sites', [
            'server_id' => $server->id,
            'domain' => 'example.com',
            'php_version' => '8.3',
        ])
        ->assertRedirect();

    expect(Site::where('domain', 'example.com')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('dispatches InstallSiteJob after creating a site', function () {
    Queue::fake();

    $user = User::factory()->create();
    $server = Server::factory()->for($user)->provisioned()->create();

    $this->actingAs($user)->post('/sites', [
        'server_id' => $server->id,
        'domain' => 'example.com',
        'php_version' => '8.3',
    ]);

    Queue::assertPushed(InstallSiteJob::class, function (InstallSiteJob $job) {
        return $job->site->domain === 'example.com';
    });
});

it('validates required fields when creating a site', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/sites', [])
        ->assertInvalid(['server_id', 'domain', 'php_version']);
});

it('prevents creating a site on another users server', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $server = Server::factory()->for($other)->provisioned()->create();

    $this->actingAs($user)
        ->post('/sites', [
            'server_id' => $server->id,
            'domain' => 'example.com',
            'php_version' => '8.3',
        ])
        ->assertInvalid(['server_id']);
});

it('auto-generates db credentials and tokens on create', function () {
    Queue::fake();

    $user = User::factory()->create();
    $server = Server::factory()->for($user)->provisioned()->create();

    $this->actingAs($user)->post('/sites', [
        'server_id' => $server->id,
        'domain' => 'mysite.example.com',
        'php_version' => '8.3',
    ]);

    $site = Site::where('user_id', $user->id)->first();

    expect($site->db_name)->not->toBeEmpty();
    expect($site->db_user)->not->toBeEmpty();
    expect($site->db_password)->toHaveLength(24);
    expect($site->install_token)->toHaveLength(64);
    expect($site->callback_signature)->toHaveLength(64);
});

// ── Show ─────────────────────────────────────────────────────

it('shows site detail page', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->provisioned()->create();
    $site = Site::factory()->for($user)->for($server)->create();

    $this->actingAs($user)
        ->get("/sites/{$site->id}")
        ->assertSuccessful()
        ->assertInertia(
            fn ($page) => $page
                ->component('sites/Show')
                ->has('site')
                ->where('site.id', $site->id)
                ->missing('site.install_url')
        );
});

it('forbids viewing another users site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->create();

    $this->actingAs($user)
        ->get("/sites/{$site->id}")
        ->assertForbidden();
});

// ── Install script ───────────────────────────────────────────

it('serves the bash install script with valid token', function () {
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->create();

    $this->get("/sites/{$site->id}/install-script?token={$site->install_token}")
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=UTF-8');
});

it('rejects install script with invalid token', function () {
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->create();

    $this->get("/sites/{$site->id}/install-script?token=bad-token")
        ->assertForbidden();
});

// ── Install callback ─────────────────────────────────────────

it('updates site status via install callback', function () {
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->create(['status' => 'pending']);

    $this->post("/callback/sites/{$site->id}/install?signature={$site->callback_signature}", [
        'status' => 'installing',
        'step' => 'Downloading WordPress',
    ])->assertSuccessful();

    $site->refresh();
    expect($site->status)->toBe('installing');
    expect($site->current_step)->toBe('Downloading WordPress');
    expect($site->install_log)->toHaveCount(1);
    expect($site->install_log[0]['step'])->toBe('Downloading WordPress');
});

it('marks site as installed on final callback', function () {
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->installing()->create();

    $this->post("/callback/sites/{$site->id}/install?signature={$site->callback_signature}", [
        'status' => 'installed',
        'step' => 'WordPress installation complete',
    ])->assertSuccessful();

    $site->refresh();
    expect($site->status)->toBe('installed');
    expect($site->installed_at)->not->toBeNull();
    expect($site->current_step)->toBeNull();
});

it('rejects install callback with invalid signature', function () {
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->create();

    $this->post("/callback/sites/{$site->id}/install?signature=invalid")
        ->assertForbidden();
});

it('accumulates install log entries across multiple callbacks', function () {
    $server = Server::factory()->for(User::factory())->provisioned()->create();
    $site = Site::factory()->for(User::factory())->for($server)->create();

    $this->post("/callback/sites/{$site->id}/install?signature={$site->callback_signature}", [
        'status' => 'installing',
        'step' => 'Creating site directories',
    ]);

    $this->post("/callback/sites/{$site->id}/install?signature={$site->callback_signature}", [
        'status' => 'installing',
        'step' => 'Downloading WordPress',
    ]);

    $site->refresh();
    expect($site->install_log)->toHaveCount(2);
});

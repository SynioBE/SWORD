<?php

use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Inertia\Testing\AssertableInertia as Assert;
use Psr\Http\Client\ClientInterface;

// ─── Settings / Integrations ───────────────────────────────────────────────

test('integrations settings page is accessible', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('integrations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Integrations'));
});

test('integrations index lists all user integrations', function () {
    $user = User::factory()->create();

    $user->integrations()->createMany([
        ['name' => 'Personal CF', 'provider' => 'cloudflare', 'credentials' => ['type' => 'api_token', 'token' => 'tok1']],
        ['name' => 'Work CF', 'provider' => 'cloudflare', 'credentials' => ['type' => 'global_key', 'email' => 'a@b.com', 'key' => 'k']],
    ]);

    $this->actingAs($user)
        ->get(route('integrations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Integrations')
            ->has('integrations', 2)
        );
});

test('integrations index does not expose raw credentials', function () {
    $user = User::factory()->create();

    $user->integrations()->create([
        'name' => 'My CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'super-secret-token'],
    ]);

    $this->actingAs($user)
        ->get(route('integrations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integrations.0.credentials.token', fn ($v) => $v !== 'super-secret-token')
        );
});

test('integration can be stored with api token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'name' => 'My Cloudflare',
            'provider' => 'cloudflare',
            'type' => 'api_token',
            'token' => 'test-token-abcdefghijklmnop',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('integrations.index'));

    $integration = $user->integrations()->first();

    expect($integration)->not->toBeNull();
    expect($integration->name)->toBe('My Cloudflare');
    expect($integration->provider)->toBe('cloudflare');
    expect($integration->credentials['type'])->toBe('api_token');
    expect($integration->credentials['token'])->toBe('test-token-abcdefghijklmnop');
});

test('integration can be stored with global api key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'name' => 'Work Account',
            'provider' => 'cloudflare',
            'type' => 'global_key',
            'email' => 'user@example.com',
            'key' => 'abc123-global-key',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('integrations.index'));

    $integration = $user->integrations()->first();

    expect($integration->credentials['type'])->toBe('global_key');
    expect($integration->credentials['email'])->toBe('user@example.com');
    expect($integration->credentials['key'])->toBe('abc123-global-key');
});

test('multiple integrations can be created for the same provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('integrations.store'), [
        'name' => 'Account A',
        'provider' => 'cloudflare',
        'type' => 'api_token',
        'token' => 'token-a',
    ]);

    $this->actingAs($user)->post(route('integrations.store'), [
        'name' => 'Account B',
        'provider' => 'cloudflare',
        'type' => 'api_token',
        'token' => 'token-b',
    ]);

    expect($user->integrations()->where('provider', 'cloudflare')->count())->toBe(2);
});

test('store integration requires name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'provider' => 'cloudflare',
            'type' => 'api_token',
            'token' => 'token',
        ])
        ->assertSessionHasErrors('name');
});

test('store integration requires a valid provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'name' => 'Test',
            'provider' => 'unknown_provider',
            'type' => 'api_token',
            'token' => 'token',
        ])
        ->assertSessionHasErrors('provider');
});

test('store integration requires a valid type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'name' => 'Test',
            'provider' => 'cloudflare',
            'type' => 'invalid_type',
        ])
        ->assertSessionHasErrors('type');
});

test('store api token is required when type is api_token', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'name' => 'Test',
            'provider' => 'cloudflare',
            'type' => 'api_token',
        ])
        ->assertSessionHasErrors('token');
});

test('store email and key are required when type is global_key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('integrations.store'), [
            'name' => 'Test',
            'provider' => 'cloudflare',
            'type' => 'global_key',
        ])
        ->assertSessionHasErrors(['email', 'key']);
});

test('integration can be updated', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'Old Name',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'old-token'],
    ]);

    $this->actingAs($user)
        ->patch(route('integrations.update', $integration->id), [
            'name' => 'New Name',
            'type' => 'api_token',
            'token' => 'new-token',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('integrations.index'));

    $integration->refresh();

    expect($integration->name)->toBe('New Name');
    expect($integration->credentials['token'])->toBe('new-token');
});

test('updating integration with blank token keeps existing token', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'original-token'],
    ]);

    $this->actingAs($user)
        ->patch(route('integrations.update', $integration->id), [
            'name' => 'CF',
            'type' => 'api_token',
            'token' => '',
        ])
        ->assertSessionHasNoErrors();

    $integration->refresh();

    expect($integration->credentials['token'])->toBe('original-token');
});

test('integration can be deleted', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'token'],
    ]);

    $this->actingAs($user)
        ->delete(route('integrations.destroy', $integration->id))
        ->assertRedirect(route('integrations.index'));

    expect($user->integrations()->count())->toBe(0);
});

test('user cannot update or delete another user\'s integration', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $integration = $owner->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'token'],
    ]);

    $this->actingAs($attacker)
        ->patch(route('integrations.update', $integration->id), [
            'name' => 'Hacked',
            'type' => 'api_token',
            'token' => 'hacked',
        ])
        ->assertNotFound();

    $this->actingAs($attacker)
        ->delete(route('integrations.destroy', $integration->id))
        ->assertNotFound();
});

test('guests cannot access integrations settings', function () {
    $this->get(route('integrations.index'))->assertRedirect(route('login'));
    $this->post(route('integrations.store'), [])->assertRedirect(route('login'));
    $this->patch(route('integrations.update', 1), [])->assertRedirect(route('login'));
    $this->delete(route('integrations.destroy', 1))->assertRedirect(route('login'));
});

// ─── Cloudflare Pages ──────────────────────────────────────────────────────

test('cloudflare index shows empty integrations list when none configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cloudflare.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('cloudflare/Index')
            ->has('integrations', 0)
        );
});

test('cloudflare index lists only cloudflare integrations', function () {
    $user = User::factory()->create();

    $user->integrations()->createMany([
        ['name' => 'CF Personal', 'provider' => 'cloudflare', 'credentials' => ['type' => 'api_token', 'token' => 'tok']],
        ['name' => 'CF Work', 'provider' => 'cloudflare', 'credentials' => ['type' => 'api_token', 'token' => 'tok2']],
    ]);

    $this->actingAs($user)
        ->get(route('cloudflare.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('cloudflare/Index')
            ->has('integrations', 2)
        );
});

test('guests cannot access cloudflare pages', function () {
    $this->get(route('cloudflare.index'))->assertRedirect(route('login'));
});

// ─── DNS Records ───────────────────────────────────────────────────────────

test('store dns record validates required fields', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $this->actingAs($user)
        ->post(route('cloudflare.dns-records.store', [$integration->id, 'zone-abc']))
        ->assertSessionHasErrors(['name', 'type', 'content']);
});

test('store dns record rejects invalid type', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $this->actingAs($user)
        ->post(route('cloudflare.dns-records.store', [$integration->id, 'zone-abc']), [
            'name' => 'app',
            'type' => 'MX',
            'content' => '1.2.3.4',
        ])
        ->assertSessionHasErrors('type');
});

test('store dns record requires cname_content when type is both', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $this->actingAs($user)
        ->post(route('cloudflare.dns-records.store', [$integration->id, 'zone-abc']), [
            'name' => 'app',
            'type' => 'both',
            'content' => '1.2.3.4',
            // cname_content intentionally missing
        ])
        ->assertSessionHasErrors('cname_content');
});

test('store dns record calls cloudflare api and redirects back', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $mockClient = Mockery::mock(ClientInterface::class);

    // getDnsRecords (upsert check) — returns empty list so a create is triggered
    $emptyResponse = new Response(200, [], json_encode(['result' => []]));

    // createDnsRecord — returns created record
    $createdResponse = new Response(200, [], json_encode([
        'result' => ['id' => 'rec-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '1.2.3.4'],
    ]));

    $mockClient->shouldReceive('sendRequest')->twice()->andReturn($emptyResponse, $createdResponse);
    app()->instance(ClientInterface::class, $mockClient);

    $this->actingAs($user)
        ->post(route('cloudflare.dns-records.store', [$integration->id, 'zone-abc']), [
            'name' => 'app.example.com',
            'type' => 'A',
            'content' => '1.2.3.4',
            'proxied' => false,
            'ttl' => 1,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

test('destroy dns record calls cloudflare api and redirects back', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $mockClient = Mockery::mock(ClientInterface::class);

    $deleteResponse = new Response(200, [], json_encode([
        'result' => ['id' => 'rec-1'],
    ]));

    $mockClient->shouldReceive('sendRequest')->once()->andReturn($deleteResponse);
    app()->instance(ClientInterface::class, $mockClient);

    $this->actingAs($user)
        ->delete(route('cloudflare.dns-records.destroy', [$integration->id, 'zone-abc', 'rec-1']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

test('update dns record validates required fields', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $this->actingAs($user)
        ->patch(route('cloudflare.dns-records.update', [$integration->id, 'zone-abc', 'rec-1']))
        ->assertSessionHasErrors(['name', 'type', 'content']);
});

test('update dns record rejects invalid type (both is not allowed)', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $this->actingAs($user)
        ->patch(route('cloudflare.dns-records.update', [$integration->id, 'zone-abc', 'rec-1']), [
            'name' => 'app',
            'type' => 'both',
            'content' => '1.2.3.4',
        ])
        ->assertSessionHasErrors('type');
});

test('update dns record calls cloudflare api and redirects back', function () {
    $user = User::factory()->create();

    $integration = $user->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $mockClient = Mockery::mock(ClientInterface::class);

    $updatedResponse = new Response(200, [], json_encode([
        'result' => ['id' => 'rec-1', 'type' => 'A', 'name' => 'app.example.com', 'content' => '5.6.7.8'],
    ]));

    $mockClient->shouldReceive('sendRequest')->once()->andReturn($updatedResponse);
    app()->instance(ClientInterface::class, $mockClient);

    $this->actingAs($user)
        ->patch(route('cloudflare.dns-records.update', [$integration->id, 'zone-abc', 'rec-1']), [
            'name' => 'app.example.com',
            'type' => 'A',
            'content' => '5.6.7.8',
            'proxied' => false,
            'ttl' => 1,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

test('user cannot modify dns records of another user integration', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $integration = $owner->integrations()->create([
        'name' => 'CF',
        'provider' => 'cloudflare',
        'credentials' => ['type' => 'api_token', 'token' => 'tok'],
    ]);

    $this->actingAs($attacker)
        ->post(route('cloudflare.dns-records.store', [$integration->id, 'zone-abc']), [
            'name' => 'app',
            'type' => 'A',
            'content' => '1.2.3.4',
        ])
        ->assertNotFound();

    $this->actingAs($attacker)
        ->patch(route('cloudflare.dns-records.update', [$integration->id, 'zone-abc', 'rec-1']), [
            'name' => 'app',
            'type' => 'A',
            'content' => '1.2.3.4',
        ])
        ->assertNotFound();

    $this->actingAs($attacker)
        ->delete(route('cloudflare.dns-records.destroy', [$integration->id, 'zone-abc', 'rec-1']))
        ->assertNotFound();
});

test('guests cannot access dns record endpoints', function () {
    $this->post(route('cloudflare.dns-records.store', [1, 'zone-abc']))->assertRedirect(route('login'));
    $this->patch(route('cloudflare.dns-records.update', [1, 'zone-abc', 'rec-1']))->assertRedirect(route('login'));
    $this->delete(route('cloudflare.dns-records.destroy', [1, 'zone-abc', 'rec-1']))->assertRedirect(route('login'));
});

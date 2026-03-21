<?php

use App\Models\User;
use App\Services\ServerNameGenerator;

test('guests cannot access generate-name endpoint', function () {
    $this->getJson(route('servers.generate-name'))
        ->assertUnauthorized();
});

test('authenticated users can generate a server name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('servers.generate-name'))
        ->assertSuccessful()
        ->assertJsonStructure(['name', 'hostname'])
        ->assertJsonIsObject();
});

test('generated name is adjective-animal format', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson(route('servers.generate-name'))
        ->assertSuccessful();

    $name = $response->json('name');
    expect($name)->toMatch('/^[a-z]+-[a-z]+$/');
});

test('generated hostname matches the name slugified', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->getJson(route('servers.generate-name'))
        ->assertSuccessful();

    $name = $response->json('name');
    $hostname = $response->json('hostname');

    expect($hostname)->toBe(str_replace(' ', '-', strtolower($name)));
});

test('ServerNameGenerator always produces a non-empty hyphenated string', function () {
    $generator = new ServerNameGenerator;

    foreach (range(1, 20) as $i) {
        $name = $generator->generate();
        expect($name)
            ->toMatch('/^[a-z]+-[a-z]+$/')
            ->toContain('-');
    }
});

test('ServerNameGenerator toHostname slugifies correctly', function () {
    $generator = new ServerNameGenerator;

    expect($generator->toHostname('cool turtle'))->toBe('cool-turtle');
    expect($generator->toHostname('blazing-fox'))->toBe('blazing-fox');
    expect($generator->toHostname('UPPER CASE'))->toBe('upper-case');
});

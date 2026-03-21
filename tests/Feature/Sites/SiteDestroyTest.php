<?php

use App\Jobs\DeleteSiteJob;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('guests cannot delete a site', function () {
    $site = Site::factory()->create();

    $this->deleteJson(route('sites.destroy', $site))
        ->assertUnauthorized();
});

test('users cannot delete a site belonging to another user', function () {
    $owner = User::factory()->create();
    $site = Site::factory()->create(['user_id' => $owner->id]);

    $this->actingAs(User::factory()->create())
        ->delete(route('sites.destroy', $site))
        ->assertForbidden();
});

test('deleting a site redirects to the sites index', function () {
    Queue::fake();

    $user = User::factory()->create();
    $site = Site::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('sites.destroy', $site))
        ->assertRedirect(route('sites.index'));
});

test('deleting a site dispatches DeleteSiteJob', function () {
    Queue::fake();

    $user = User::factory()->create();
    $site = Site::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('sites.destroy', $site));

    Queue::assertPushed(DeleteSiteJob::class, fn ($job) => $job->site->id === $site->id);
});

test('delete script endpoint requires a valid token', function () {
    $site = Site::factory()->create(['install_token' => str_repeat('a', 64)]);

    $this->get(route('sites.scripts.delete', ['site' => $site, 'token' => 'wrong']))
        ->assertForbidden();
});

test('delete script endpoint returns a shell script with the correct token', function () {
    $site = Site::factory()->installed()->create();

    $this->get(route('sites.scripts.delete', ['site' => $site, 'token' => $site->install_token]))
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');
});

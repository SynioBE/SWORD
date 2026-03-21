<?php

use App\Jobs\RunBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('owner can trigger a manual backup run', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    $schedule = BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
    ]);

    $this->actingAs($user)
        ->post(route('servers.backup-schedules.run', [$server, $schedule]))
        ->assertRedirect(route('servers.show', $server));

    Queue::assertPushed(RunBackupJob::class, function ($job) use ($schedule) {
        return $job->schedule->id === $schedule->id;
    });
});

test('non-owner cannot trigger a manual backup run', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $server = Server::factory()->for($owner)->create(['status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($owner)->create();

    $schedule = BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
    ]);

    $this->actingAs($other)
        ->post(route('servers.backup-schedules.run', [$server, $schedule]))
        ->assertForbidden();

    Queue::assertNotPushed(RunBackupJob::class);
});

test('guests cannot trigger a manual backup run', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    $schedule = BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
    ]);

    $this->post(route('servers.backup-schedules.run', [$server, $schedule]))
        ->assertRedirect(route('login'));

    Queue::assertNotPushed(RunBackupJob::class);
});

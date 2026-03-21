<?php

use App\Jobs\RunBackupJob;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\Server;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('dispatches daily backup when time matches', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    Carbon::setTestNow(Carbon::parse('2026-03-21 02:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'daily',
        'time' => '02:00',
        'is_enabled' => true,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertPushed(RunBackupJob::class);
});

test('does not dispatch daily backup when time does not match', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    Carbon::setTestNow(Carbon::parse('2026-03-21 03:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'daily',
        'time' => '02:00',
        'is_enabled' => true,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertNotPushed(RunBackupJob::class);
});

test('dispatches weekly backup on matching day and time', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    // 2026-03-21 is a Saturday (dayOfWeek = 6)
    Carbon::setTestNow(Carbon::parse('2026-03-21 02:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'weekly',
        'time' => '02:00',
        'day_of_week' => 6,
        'is_enabled' => true,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertPushed(RunBackupJob::class);
});

test('does not dispatch weekly backup on wrong day', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    // 2026-03-21 is Saturday (6), schedule is for Monday (1)
    Carbon::setTestNow(Carbon::parse('2026-03-21 02:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'weekly',
        'time' => '02:00',
        'day_of_week' => 1,
        'is_enabled' => true,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertNotPushed(RunBackupJob::class);
});

test('dispatches monthly backup on matching day', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    Carbon::setTestNow(Carbon::parse('2026-03-15 02:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'monthly',
        'time' => '02:00',
        'day_of_month' => 15,
        'is_enabled' => true,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertPushed(RunBackupJob::class);
});

test('does not dispatch disabled schedules', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    Carbon::setTestNow(Carbon::parse('2026-03-21 02:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'daily',
        'time' => '02:00',
        'is_enabled' => false,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertNotPushed(RunBackupJob::class);
});

test('skips schedule if a backup is already running', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    Carbon::setTestNow(Carbon::parse('2026-03-21 02:00', 'UTC'));

    $schedule = BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'daily',
        'time' => '02:00',
        'is_enabled' => true,
    ]);

    BackupRun::factory()->running()->create([
        'backup_schedule_id' => $schedule->id,
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertNotPushed(RunBackupJob::class);
});

test('skips schedule if already ran successfully today', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'UTC', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    Carbon::setTestNow(Carbon::parse('2026-03-21 02:00', 'UTC'));

    $schedule = BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'daily',
        'time' => '02:00',
        'is_enabled' => true,
    ]);

    BackupRun::factory()->create([
        'backup_schedule_id' => $schedule->id,
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'status' => 'completed',
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertNotPushed(RunBackupJob::class);
});

test('respects server timezone for scheduling', function () {
    $user = User::factory()->create();
    $server = Server::factory()->for($user)->create(['timezone' => 'America/New_York', 'status' => 'provisioned']);
    $destination = BackupDestination::factory()->for($user)->create();

    // 2026-03-21 is during EDT (UTC-4), so 02:00 EDT = 06:00 UTC
    Carbon::setTestNow(Carbon::parse('2026-03-21 06:00', 'UTC'));

    BackupSchedule::factory()->create([
        'server_id' => $server->id,
        'backup_destination_id' => $destination->id,
        'frequency' => 'daily',
        'time' => '02:00',
        'is_enabled' => true,
    ]);

    $this->artisan('backup:dispatch')->assertSuccessful();

    Queue::assertPushed(RunBackupJob::class);
});

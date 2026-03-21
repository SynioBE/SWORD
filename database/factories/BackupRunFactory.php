<?php

namespace Database\Factories;

use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'backup_schedule_id' => BackupSchedule::factory(),
            'server_id' => Server::factory(),
            'backup_destination_id' => BackupDestination::factory(),
            'status' => 'completed',
            'output' => 'Backup completed successfully.',
            'archive_name' => 'server-'.now()->format('Y-m-d\TH:i'),
            'size_bytes' => $this->faker->numberBetween(1_000_000, 10_000_000_000),
            'duration_seconds' => $this->faker->numberBetween(10, 3600),
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => 'running',
            'output' => null,
            'archive_name' => null,
            'size_bytes' => null,
            'duration_seconds' => null,
            'completed_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'output' => 'Connection refused',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'output' => null,
            'archive_name' => null,
            'size_bytes' => null,
            'duration_seconds' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }
}

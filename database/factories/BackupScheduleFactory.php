<?php

namespace Database\Factories;

use App\Models\BackupDestination;
use App\Models\BackupSchedule;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupSchedule>
 */
class BackupScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'backup_destination_id' => BackupDestination::factory(),
            'frequency' => $this->faker->randomElement(['daily', 'weekly', 'monthly']),
            'time' => $this->faker->time('H:i'),
            'day_of_week' => null,
            'day_of_month' => null,
            'retention_count' => 7,
            'is_enabled' => true,
        ];
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
            'day_of_week' => $this->faker->numberBetween(0, 6),
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'day_of_month' => $this->faker->numberBetween(1, 28),
        ]);
    }
}

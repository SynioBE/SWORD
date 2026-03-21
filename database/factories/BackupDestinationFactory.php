<?php

namespace Database\Factories;

use App\Models\BackupDestination;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupDestination>
 */
class BackupDestinationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->word().'-'.fake()->word().' backup',
            'type' => 'borg',
            'host' => $this->faker->ipv4(),
            'port' => 22,
            'username' => $this->faker->userName(),
            'auth_method' => 'password',
            'password' => $this->faker->password(),
            'ssh_private_key' => null,
            'storage_path' => '/backups',
            'status' => 'pending',
            'last_connected_at' => null,
        ];
    }

    public function sshKey(): static
    {
        return $this->state(fn (array $attributes) => [
            'auth_method' => 'ssh_key',
            'password' => null,
            'ssh_private_key' => 'fake-ssh-private-key',
        ]);
    }
}

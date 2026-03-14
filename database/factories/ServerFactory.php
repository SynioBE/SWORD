<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $privateKey = EC::createKey('Ed25519');

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true).' server',
            'ip_address' => $this->faker->ipv4(),
            'hostname' => $this->faker->domainName(),
            'timezone' => 'UTC',
            'region' => $this->faker->randomElement(['us-east-1', 'eu-west-1', 'ap-southeast-1']),
            'provider' => $this->faker->randomElement(['hetzner', 'digitalocean', 'linode', 'vultr', 'custom']),
            'ssh_port' => 22,
            'ssh_public_key' => $privateKey->getPublicKey()->toString('OpenSSH', ['comment' => 'sword']),
            'ssh_private_key' => $privateKey->toString('OpenSSH'),
            'provision_token' => Str::random(64),
            'callback_signature' => hash('sha256', Str::random(40)),
            'status' => 'pending',
            'current_step' => null,
            'provision_log' => [],
            'provisioned_at' => null,
        ];
    }

    public function provisioned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'provisioned',
            'provisioned_at' => now(),
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'provisioning',
            'current_step' => 'Installing Docker',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}

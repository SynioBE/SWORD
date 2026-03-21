<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = $this->faker->domainName();
        $dbSlug = str_replace(['.', '-'], '_', explode('.', $domain)[0]);

        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
            'site_label' => $this->faker->optional()->words(2, true),
            'domain' => $domain,
            'php_version' => $this->faker->randomElement(['8.1', '8.2', '8.3', '8.4']),
            'db_name' => $dbSlug.'_db',
            'db_user' => $dbSlug.'_user',
            'db_password' => Str::password(32, symbols: false),
            'install_token' => Str::random(64),
            'callback_signature' => hash('sha256', Str::random(40)),
            'status' => 'pending',
            'current_step' => null,
            'install_log' => null,
            'installed_at' => null,
        ];
    }

    public function installed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'installed',
            'installed_at' => now(),
        ]);
    }

    public function installing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'installing',
            'current_step' => 'nginx_setup',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}

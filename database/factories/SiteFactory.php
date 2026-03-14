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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = $this->faker->domainName();
        $dbSlug = str_replace(['.', '-'], '_', $this->faker->slug(2));

        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
            'domain' => $domain,
            'php_version' => '8.3',
            'db_name' => $dbSlug,
            'db_user' => $dbSlug.'_user',
            'db_password' => Str::random(24),
            'install_token' => Str::random(64),
            'callback_signature' => hash('sha256', Str::random(40)),
            'status' => 'pending',
            'current_step' => null,
            'install_log' => [],
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
            'current_step' => 'Installing WordPress',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}

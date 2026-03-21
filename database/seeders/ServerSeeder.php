<?php

namespace Database\Seeders;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Seeder;

class ServerSeeder extends Seeder
{
    public function run(): void
    {
        User::all()->each(function (User $user): void {
            Server::factory(2)->for($user)->create();
            Server::factory()->for($user)->provisioned()->create();
        });
    }
}

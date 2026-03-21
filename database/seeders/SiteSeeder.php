<?php

namespace Database\Seeders;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\Seeder;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        Server::with('user')->get()->each(function (Server $server): void {
            Site::factory(2)->for($server)->for($server->user)->create();
            Site::factory()->for($server)->for($server->user)->installed()->create();
            Site::factory()->for($server)->for($server->user)->failed()->create();
        });
    }
}

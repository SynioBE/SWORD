<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class PingServers extends Command
{
    protected $signature = 'servers:ping';

    protected $description = 'Check online status of all provisioned servers via TCP';

    public function handle(): void
    {
        $servers = Server::query()
            ->where('status', 'provisioned')
            ->whereNotNull('ip_address')
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        // Open all connections simultaneously in non-blocking mode
        $pending = [];

        foreach ($servers as $server) {
            $socket = @stream_socket_client(
                "tcp://{$server->ip_address}:{$server->ssh_port}",
                $errno,
                $errstr,
                0, // Must be 0 with STREAM_CLIENT_ASYNC_CONNECT for truly non-blocking
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
            );

            if ($socket !== false) {
                stream_set_blocking($socket, false);
                $pending[$server->id] = $socket;
            }
        }

        // Wait at most 5 seconds for all connections to complete in parallel
        $writable = array_values($pending);
        $readable = [];
        $exceptional = array_values($pending); // Must watch for errors too

        if (! empty($writable)) {
            stream_select($readable, $writable, $exceptional, 5);
        }

        // Update each server individually based on its connection result
        $now = now();

        foreach ($servers as $server) {
            $socket = $pending[$server->id] ?? null;

            $online = $socket !== null
                      && in_array($socket, $writable, strict: true)
                      && ! in_array($socket, $exceptional, strict: true);

            if ($socket !== null) {
                fclose($socket);
            }

            $server->update([
                'is_online' => $online,
                'last_pinged_at' => $now,
            ]);
        }
    }
}

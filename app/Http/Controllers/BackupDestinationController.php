<?php

namespace App\Http\Controllers;

use App\Http\Requests\BackupDestinations\StoreBackupDestinationRequest;
use App\Http\Requests\BackupDestinations\UpdateBackupDestinationRequest;
use App\Models\BackupDestination;
use App\Services\ServerNameGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

class BackupDestinationController extends Controller
{
    public function generateName(ServerNameGenerator $generator): JsonResponse
    {
        return response()->json([
            'name' => $generator->generate(),
        ]);
    }

    public function index(Request $request): Response
    {
        $destinations = $request->user()
            ->backupDestinations()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BackupDestination $destination) => [
                'id' => $destination->id,
                'name' => $destination->name,
                'type' => $destination->type,
                'host' => $destination->host,
                'port' => $destination->port,
                'username' => $destination->username,
                'storage_path' => $destination->storage_path,
                'status' => $destination->status,
                'last_connected_at' => $destination->last_connected_at?->toIso8601String(),
                'created_at' => $destination->created_at->toIso8601String(),
            ]);

        return Inertia::render('backup-destinations/Index', [
            'destinations' => $destinations,
        ]);
    }

    public function store(StoreBackupDestinationRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $connectionResult = $this->testConnection($validated);

        $destination = $request->user()->backupDestinations()->create(array_merge($validated, [
            'status' => $connectionResult['connected'] ? 'connected' : 'error',
            'last_connected_at' => $connectionResult['connected'] ? now() : null,
        ]));

        if (! $connectionResult['connected']) {
            return redirect()->route('backup-destinations.show', $destination)
                ->with('error', $connectionResult['error']);
        }

        return redirect()->route('backup-destinations.show', $destination);
    }

    /**
     * @return array{connected: bool, error: string|null}
     */
    private function testConnection(array $config): array
    {
        try {
            $ssh = new SSH2($config['host'], $config['port']);
            $ssh->setTimeout(10);

            if ($config['auth_method'] === 'ssh_key') {
                $key = PublicKeyLoader::load($config['ssh_private_key']);
                $authenticated = $ssh->login($config['username'], $key);
            } else {
                $authenticated = $ssh->login($config['username'], $config['password']);
            }

            if (! $authenticated) {
                return ['connected' => false, 'error' => 'Authentication failed. Check your credentials.'];
            }

            $storagePath = rtrim($config['storage_path'], '/');
            $escapedPath = escapeshellarg($storagePath);

            // Check if directory exists, create it if not
            $result = $ssh->exec("test -d {$escapedPath} && echo EXISTS || mkdir -p {$escapedPath} && echo CREATED 2>&1");

            if (! str_contains($result, 'EXISTS') && ! str_contains($result, 'CREATED')) {
                $ssh->disconnect();

                return ['connected' => false, 'error' => "Could not access or create storage path: {$storagePath}"];
            }

            $ssh->disconnect();

            return ['connected' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => 'Connection failed: '.$e->getMessage()];
        }
    }

    public function show(Request $request, BackupDestination $backupDestination): Response
    {
        abort_unless($backupDestination->user_id === $request->user()->id, 403);

        $schedules = $backupDestination->backupSchedules()
            ->with('server')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($schedule) => [
                'id' => $schedule->id,
                'server_name' => $schedule->server->name,
                'server_id' => $schedule->server_id,
                'frequency' => $schedule->frequency,
                'time' => $schedule->time,
                'day_of_week' => $schedule->day_of_week,
                'day_of_month' => $schedule->day_of_month,
                'retention_count' => $schedule->retention_count,
                'is_enabled' => $schedule->is_enabled,
                'created_at' => $schedule->created_at->toIso8601String(),
            ]);

        $recentRuns = $backupDestination->backupRuns()
            ->with('server')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'server_name' => $run->server->name,
                'status' => $run->status,
                'archive_name' => $run->archive_name,
                'size_bytes' => $run->size_bytes,
                'duration_seconds' => $run->duration_seconds,
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'created_at' => $run->created_at->toIso8601String(),
            ]);

        return Inertia::render('backup-destinations/Show', [
            'destination' => [
                'id' => $backupDestination->id,
                'name' => $backupDestination->name,
                'type' => $backupDestination->type,
                'host' => $backupDestination->host,
                'port' => $backupDestination->port,
                'username' => $backupDestination->username,
                'auth_method' => $backupDestination->auth_method,
                'storage_path' => $backupDestination->storage_path,
                'status' => $backupDestination->status,
                'last_connected_at' => $backupDestination->last_connected_at?->toIso8601String(),
                'created_at' => $backupDestination->created_at->toIso8601String(),
            ],
            'schedules' => $schedules,
            'recentRuns' => $recentRuns,
        ]);
    }

    public function update(UpdateBackupDestinationRequest $request, BackupDestination $backupDestination): RedirectResponse
    {
        abort_unless($backupDestination->user_id === $request->user()->id, 403);

        $validated = $request->validated();

        // Preserve existing credentials when empty strings are submitted
        if ($backupDestination->auth_method === 'password' && empty($validated['password'])) {
            $validated['password'] = $backupDestination->password;
        }

        if ($backupDestination->auth_method === 'ssh_key' && empty($validated['ssh_private_key'])) {
            $validated['ssh_private_key'] = $backupDestination->ssh_private_key;
        }

        $connectionResult = $this->testConnection($validated);

        $backupDestination->update(array_merge($validated, [
            'status' => $connectionResult['connected'] ? 'connected' : 'error',
            'last_connected_at' => $connectionResult['connected'] ? now() : $backupDestination->last_connected_at,
        ]));

        if (! $connectionResult['connected']) {
            return redirect()->route('backup-destinations.show', $backupDestination)
                ->with('error', $connectionResult['error']);
        }

        return redirect()->route('backup-destinations.show', $backupDestination);
    }

    public function destroy(Request $request, BackupDestination $backupDestination): RedirectResponse
    {
        abort_unless($backupDestination->user_id === $request->user()->id, 403);

        $backupDestination->delete();

        return redirect()->route('backup-destinations.index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\BackupDestinations\StoreBackupDestinationRequest;
use App\Models\BackupDestination;
use App\Services\ServerNameGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        $destination = $request->user()->backupDestinations()->create($request->validated());

        return redirect()->route('backup-destinations.show', $destination);
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
        ]);
    }

    public function destroy(Request $request, BackupDestination $backupDestination): RedirectResponse
    {
        abort_unless($backupDestination->user_id === $request->user()->id, 403);

        $backupDestination->delete();

        return redirect()->route('backup-destinations.index');
    }
}

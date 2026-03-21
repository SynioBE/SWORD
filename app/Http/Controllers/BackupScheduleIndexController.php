<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BackupScheduleIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $schedules = $request->user()
            ->servers()
            ->with(['backupSchedules.backupDestination', 'backupSchedules.latestBackupRun.site'])
            ->get()
            ->flatMap(fn ($server) => $server->backupSchedules->map(fn ($schedule) => [
                'id' => $schedule->id,
                'server_id' => $server->id,
                'server_name' => $server->name,
                'destination_name' => $schedule->backupDestination->name,
                'frequency' => $schedule->frequency,
                'time' => $schedule->time,
                'day_of_week' => $schedule->day_of_week,
                'day_of_month' => $schedule->day_of_month,
                'retention_count' => $schedule->retention_count,
                'is_enabled' => $schedule->is_enabled,
                'created_at' => $schedule->created_at->toIso8601String(),
                'last_run' => $schedule->latestBackupRun ? [
                    ...$schedule->latestBackupRun->only(['id', 'status', 'completed_at']),
                    'site_domain' => $schedule->latestBackupRun->site?->domain,
                ] : null,
            ]))
            ->sortByDesc('created_at')
            ->values();

        return Inertia::render('backup-schedules/Index', [
            'schedules' => $schedules,
        ]);
    }
}

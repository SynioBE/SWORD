<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\BackupSchedule;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DispatchDueBackups extends Command
{
    protected $signature = 'backup:dispatch';

    protected $description = 'Dispatch backup jobs for all due schedules';

    public function handle(): int
    {
        $schedules = BackupSchedule::query()
            ->where('is_enabled', true)
            ->with(['server', 'backupDestination'])
            ->get();

        $dispatched = 0;

        foreach ($schedules as $schedule) {
            if (! $this->isDue($schedule)) {
                continue;
            }

            if ($this->hasRunningBackup($schedule)) {
                $this->components->warn("Skipping schedule #{$schedule->id} — already running");

                continue;
            }

            if ($this->alreadyRanInCurrentPeriod($schedule)) {
                continue;
            }

            RunBackupJob::dispatch($schedule);
            $dispatched++;
        }

        $this->components->info("Dispatched {$dispatched} backup job(s).");

        return self::SUCCESS;
    }

    private function isDue(BackupSchedule $schedule): bool
    {
        $timezone = $schedule->server->timezone ?? 'UTC';
        $now = Carbon::now($timezone);
        $scheduledTime = Carbon::parse($schedule->time, $timezone);

        if ($now->format('H:i') !== $scheduledTime->format('H:i')) {
            return false;
        }

        return match ($schedule->frequency) {
            'daily' => true,
            'weekly' => $now->dayOfWeek === $schedule->day_of_week,
            'monthly' => $now->day === $schedule->day_of_month,
            default => false,
        };
    }

    private function hasRunningBackup(BackupSchedule $schedule): bool
    {
        return $schedule->backupRuns()
            ->where('status', 'running')
            ->exists();
    }

    private function alreadyRanInCurrentPeriod(BackupSchedule $schedule): bool
    {
        $timezone = $schedule->server->timezone ?? 'UTC';
        $now = Carbon::now($timezone);

        $query = $schedule->backupRuns()->where('status', 'completed');

        // Compute period boundaries in server timezone, then convert to UTC for querying
        [$periodStart, $periodEnd] = match ($schedule->frequency) {
            'daily' => [
                $now->copy()->startOfDay()->utc(),
                $now->copy()->endOfDay()->utc(),
            ],
            'weekly' => [
                $now->copy()->startOfWeek()->utc(),
                $now->copy()->endOfWeek()->utc(),
            ],
            'monthly' => [
                $now->copy()->startOfMonth()->utc(),
                $now->copy()->endOfMonth()->utc(),
            ],
            default => [null, null],
        };

        if ($periodStart === null) {
            return false;
        }

        return $query->whereBetween('created_at', [$periodStart, $periodEnd])->exists();
    }
}

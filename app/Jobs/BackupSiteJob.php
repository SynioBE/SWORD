<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Models\Site;
use App\Services\Backup\BackupDriverManager;
use App\Services\SSH\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BackupSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public Site $site,
        public BackupSchedule $schedule,
    ) {}

    public function handle(BackupDriverManager $manager): void
    {
        $schedule = $this->schedule->loadMissing(['server', 'backupDestination']);
        $site = $this->site;
        $destination = $schedule->backupDestination;
        $server = $schedule->server;

        $run = BackupRun::create([
            'backup_schedule_id' => $schedule->id,
            'server_id' => $server->id,
            'site_id' => $site->id,
            'backup_destination_id' => $destination->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $driver = $manager->driver($destination->type);
        $ssh = new SSHService($server, $this->timeout);
        $output = '';

        try {
            $ssh->connect();
            $driver->ensureInstalled($ssh);

            $setupResult = $driver->setup($ssh, $destination, $server, $site);
            $output .= "[setup]\n".$setupResult->output."\n".$setupResult->stderr."\n";

            $setupSucceeded = $setupResult->exitCode === 0
                || str_contains($setupResult->output.$setupResult->stderr, 'already exists')
                || str_contains($setupResult->output.$setupResult->stderr, 'already initialized');

            if (! $setupSucceeded) {
                throw new \RuntimeException("Repo setup failed with exit code {$setupResult->exitCode}");
            }

            $dumpResult = $driver->dumpDatabase($ssh, $server, $site);
            $output .= "[dump]\n".$dumpResult->output."\n".$dumpResult->stderr."\n";

            if ($dumpResult->exitCode !== 0) {
                throw new \RuntimeException("Database dump failed with exit code {$dumpResult->exitCode}");
            }

            $backupResult = $driver->backup($ssh, $schedule, $site);
            $output .= "[backup]\n".$backupResult->output."\n".$backupResult->stderr."\n";

            if ($backupResult->exitCode >= 2) {
                throw new \RuntimeException("Backup failed with exit code {$backupResult->exitCode}");
            }

            $pruneResult = $driver->prune($ssh, $schedule, $site);
            $output .= "[prune]\n".$pruneResult->output."\n".$pruneResult->stderr."\n";

            $run->update([
                'status' => 'completed',
                'output' => $output,
                'archive_name' => $this->parseArchiveName($backupResult->output),
                'size_bytes' => $this->parseSizeBytes($backupResult->output),
                'duration_seconds' => (int) abs(now()->diffInSeconds($run->started_at)),
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $output .= "\n[error]\n".$e->getMessage();

            $run->update([
                'status' => 'failed',
                'output' => $output,
                'duration_seconds' => (int) abs(now()->diffInSeconds($run->started_at)),
                'completed_at' => now(),
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    private function parseArchiveName(string $output): ?string
    {
        if (preg_match('/Archive name: (.+)/', $output, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function parseSizeBytes(string $output): ?int
    {
        if (preg_match('/This archive:\s+([\d.]+)\s+(\w+)/', $output, $matches)) {
            $size = (float) $matches[1];

            return (int) match ($matches[2]) {
                'kB' => $size * 1_000,
                'MB' => $size * 1_000_000,
                'GB' => $size * 1_000_000_000,
                default => $size,
            };
        }

        return null;
    }
}

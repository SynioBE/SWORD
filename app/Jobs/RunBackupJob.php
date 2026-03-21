<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Models\BackupSchedule;
use App\Services\Backup\BackupDriverManager;
use App\Services\SSH\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public BackupSchedule $schedule,
    ) {}

    public function handle(BackupDriverManager $manager): void
    {
        $schedule = $this->schedule->loadMissing(['server', 'backupDestination']);
        $server = $schedule->server;
        $destination = $schedule->backupDestination;

        $run = BackupRun::create([
            'backup_schedule_id' => $schedule->id,
            'server_id' => $server->id,
            'backup_destination_id' => $destination->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $ssh = new SSHService($server, $this->timeout);
        $output = '';

        try {
            $driver = $manager->driver($destination->type);

            $ssh->connect();

            $driver->ensureInstalled($ssh);

            if (! $schedule->repo_initialized) {
                $initResult = $driver->initializeRepo($ssh, $destination, $server);
                $output .= "[init]\n".$initResult->output."\n".$initResult->stderr."\n";

                $initSucceeded = $initResult->exitCode === 0
                    || str_contains($initResult->output.$initResult->stderr, 'already exists')
                    || str_contains($initResult->output.$initResult->stderr, 'already initialized');

                if ($initSucceeded) {
                    $schedule->update(['repo_initialized' => true]);
                } else {
                    throw new \RuntimeException("Repository initialization failed with exit code {$initResult->exitCode}");
                }
            }

            $dumpResult = $driver->dumpDatabases($ssh, $server);
            $output .= "[dump]\n".$dumpResult->output."\n".$dumpResult->stderr."\n";

            if ($dumpResult->exitCode !== 0) {
                throw new \RuntimeException("Database dump failed with exit code {$dumpResult->exitCode}");
            }

            $backupResult = $driver->createBackup($ssh, $schedule);
            $output .= "[backup]\n".$backupResult->output."\n".$backupResult->stderr."\n";

            // Borg exit codes: 0 = success, 1 = warnings (e.g. permission denied on some files), 2+ = error
            if ($backupResult->exitCode >= 2) {
                throw new \RuntimeException("Backup command failed with exit code {$backupResult->exitCode}");
            }

            $pruneResult = $driver->prune($ssh, $schedule);
            $output .= "[prune]\n".$pruneResult->output."\n".$pruneResult->stderr."\n";

            if ($pruneResult->exitCode >= 2) {
                throw new \RuntimeException("Prune command failed with exit code {$pruneResult->exitCode}");
            }

            $archiveName = $this->parseArchiveName($backupResult->output);
            $sizeBytes = $this->parseSizeBytes($backupResult->output);

            $run->update([
                'status' => 'completed',
                'output' => $output,
                'archive_name' => $archiveName,
                'size_bytes' => $sizeBytes,
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

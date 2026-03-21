<?php

namespace App\Jobs;

use App\Models\BackupDestination;
use App\Models\Server;
use App\Services\Backup\BackupDriverManager;
use App\Services\SSH\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteSiteBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  array<int>  $destinationIds
     */
    public function __construct(
        public Server $server,
        public string $domain,
        public array $destinationIds,
    ) {}

    public function handle(BackupDriverManager $manager): void
    {
        $destinations = BackupDestination::whereIn('id', $this->destinationIds)->get();

        if ($destinations->isEmpty()) {
            return;
        }

        $ssh = new SSHService($this->server, $this->timeout);

        try {
            $ssh->connect();

            foreach ($destinations as $destination) {
                $driver = $manager->driver($destination->type);

                $result = $driver->cleanup($ssh, $destination, $this->server, $this->domain);

                if ($result->exitCode !== 0) {
                    Log::warning("Failed to delete backup repo for {$this->domain} on destination {$destination->name}: {$result->output} {$result->stderr}");
                }
            }
        } finally {
            $ssh->disconnect();
        }
    }
}

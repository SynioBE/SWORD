<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;

class RunAnsible implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $serverID
    )
        {
            // This should whhen a new site is created.
            // Pass on the prim ID.
    }

    /**
     * Execute the job.
    */
    public function handle(): void
    {
        // Get the record.
        // Run ansible. Laravel Processes.

        echo "Running Ansible for Server ID: " . $this->serverID . "\n";

        $command = Process::run( 'ansible --version');

        // Todo error handling.
        echo $command->output();
    }
}

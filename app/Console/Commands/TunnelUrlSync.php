<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TunnelUrlSync extends Command
{
    protected $signature = 'tunnel:sync
                            {--metrics=http://cloudflared:2000 : The cloudflared metrics endpoint}
                            {--timeout=30 : Seconds to wait for the tunnel URL to become available}';

    protected $description = 'Fetch the active cloudflared tunnel URL and update APP_URL in .env';

    public function handle(): int
    {
        $metricsUrl = $this->option('metrics');
        $timeout = (int) $this->option('timeout');
        $deadline = now()->addSeconds($timeout);

        $this->info("Waiting for cloudflared tunnel URL (timeout: {$timeout}s)…");

        $tunnelUrl = null;

        while (now()->lessThan($deadline)) {
            $tunnelUrl = $this->fetchTunnelUrl($metricsUrl);

            if ($tunnelUrl !== null) {
                break;
            }

            sleep(2);
        }

        if ($tunnelUrl === null) {
            $this->error('Could not retrieve the tunnel URL within the timeout period.');
            $this->line('Make sure cloudflared is running: <comment>vendor/bin/sail up -d</comment>');

            return self::FAILURE;
        }

        $this->updateEnvUrl($tunnelUrl);

        $this->info("APP_URL updated to: <comment>{$tunnelUrl}</comment>");
        $this->line('');
        $this->line('Config cache must be cleared for the change to take effect:');
        $this->line('  <comment>vendor/bin/sail artisan config:clear</comment>');

        return self::SUCCESS;
    }

    private function fetchTunnelUrl(string $metricsUrl): ?string
    {
        try {
            $response = Http::timeout(3)->get("{$metricsUrl}/quicktunnel");

            if ($response->successful()) {
                /** @var array{hostname?: string} $json */
                $json = $response->json();

                if (! empty($json['hostname'])) {
                    return 'https://'.$json['hostname'];
                }
            }
        } catch (\Throwable) {
            // Service not yet ready — will retry
        }

        return null;
    }

    private function updateEnvUrl(string $url): void
    {
        $envPath = base_path('.env');
        $contents = file_get_contents($envPath);

        $updated = preg_replace(
            '/^APP_URL=.*/m',
            'APP_URL='.$url,
            $contents,
            limit: 1,
            count: $replacements,
        );

        if ($replacements === 0) {
            $updated = $contents."\nAPP_URL={$url}\n";
        }

        file_put_contents($envPath, $updated);
    }
}

<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\Server;
use App\Services\Cloud\DigitalOcean\CreateDigitalOceanDropletData;
use App\Services\Cloud\DigitalOcean\DigitalOceanDropletCreator;
use App\Services\Cloud\Hetzner\CreateHetznerServerData;
use App\Services\Cloud\Hetzner\HetznerServerCreator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SSH2;

class CreateCloudServerJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public readonly Server $server,
        public readonly Integration $integration,
    ) {}

    public function handle(
        HetznerServerCreator $hetznerCreator,
        DigitalOceanDropletCreator $doCreator,
    ): void {
        $apiKey = $this->integration->credentials['token'];
        $image = $this->server->image ?? $this->defaultImage();

        try {
            $ip = match ($this->integration->provider) {
                'hetzner' => $this->createHetznerServer($hetznerCreator, $apiKey, $image),
                'digital_ocean' => $this->createDigitalOceanDroplet($doCreator, $apiKey, $image),
                default => throw new \RuntimeException("Unsupported provider: {$this->integration->provider}"),
            };

            $this->server->update(['ip_address' => $ip]);

            $this->runProvisionScript();
        } catch (\Throwable $e) {
            $this->server->update(['status' => 'failed']);

            throw $e;
        }
    }

    private function createHetznerServer(HetznerServerCreator $creator, string $apiKey, string $image): string
    {
        $result = $creator->create(new CreateHetznerServerData(
            apiKey: $apiKey,
            name: $this->server->hostname,
            serverType: $this->server->server_type,
            location: $this->server->region,
            image: $image,
            publicKey: $this->server->ssh_public_key,
        ));

        return $result->publicIp ?? throw new \RuntimeException('Hetzner server created but no public IP was assigned.');
    }

    private function createDigitalOceanDroplet(DigitalOceanDropletCreator $creator, string $apiKey, string $image): string
    {
        $result = $creator->create(new CreateDigitalOceanDropletData(
            apiKey: $apiKey,
            name: $this->server->hostname,
            serverType: $this->server->server_type,
            region: $this->server->region,
            image: $image,
            publicKey: $this->server->ssh_public_key,
        ));

        return $result->publicIp ?? throw new \RuntimeException('DigitalOcean droplet created but no public IP was assigned.');
    }

    private function runProvisionScript(): void
    {
        $provisionUrl = rtrim(config('app.url'), '/').route('servers.scripts.provision', [
            'server' => $this->server->id,
            'token' => $this->server->provision_token,
        ], false);

        $privateKey = EC::loadFormat('OpenSSH', $this->server->ssh_private_key);

        $ssh = $this->waitForSsh($this->server->ip_address, $this->server->ssh_port, $privateKey);

        $ssh->setTimeout(0);
        $ssh->exec(sprintf(
            'wget -qO sword-provision.sh "%s" && nohup bash sword-provision.sh > sword-provision.log 2>&1 < /dev/null &',
            $provisionUrl,
        ));
    }

    private function waitForSsh(string $ip, int $port, mixed $privateKey, int $maxAttempts = 20, int $intervalSeconds = 15): SSH2
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $ssh = new SSH2($ip, $port, 10);
                if ($ssh->login('root', $privateKey)) {
                    return $ssh;
                }
            } catch (\Throwable) {
                // VM not ready yet
            }

            if ($attempt < $maxAttempts) {
                sleep($intervalSeconds);
            }
        }

        throw new \RuntimeException("Could not connect to {$ip}:{$port} via SSH after {$maxAttempts} attempts.");
    }

    private function defaultImage(): string
    {
        return match ($this->integration->provider) {
            'digital_ocean' => 'ubuntu-24-04-x64',
            'hetzner' => 'ubuntu-24.04',
            default => 'ubuntu-24.04',
        };
    }
}

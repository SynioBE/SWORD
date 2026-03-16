<?php

namespace App\Console\Commands;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

class CreateDigitalOceanDroplet extends Command
{
    protected $signature = 'digitalocean:droplet:create
                            {--key= : The API key}
                            {--name= : The droplet name (a random one will be generated if not provided)}
                            {--region=nyc1 : The region}
                            {--size=s-1vcpu-1gb : The size}
                            {--image=ubuntu-24-04-x64 : The image}
                            {--public-key= : Name of an existing DigitalOcean SSH key, or a raw public key string (required)}';

    protected $description = 'Creates a DigitalOcean droplet';

    private const DO_API_BASE = 'https://api.digitalocean.com/v2';

    public function __construct(private readonly ClientInterface $httpClient)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apiKey = $this->option('key') ?? config('services.digitalocean.token');

        if (empty($apiKey)) {
            $this->error('An API key is required. Pass --key=<token> or set DIGITALOCEAN_TOKEN in your environment.');

            return self::FAILURE;
        }

        $publicKeyOption = $this->option('public-key');

        if (empty($publicKeyOption)) {
            $this->error('--public-key is required. Provide an existing DigitalOcean key name or a raw public key string.');

            return self::FAILURE;
        }

        $factory = new HttpFactory;

        $this->info('Resolving SSH key…');

        try {
            $sshKeyId = $this->resolveSSHKey($publicKeyOption, $apiKey, $factory);
        } catch (ClientExceptionInterface $e) {
            $this->error('Failed to resolve SSH key: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($sshKeyId === null) {
            $this->error('Could not find or upload the provided SSH key.');

            return self::FAILURE;
        }

        $payload = json_encode([
            'name'     => $this->option('name') ?? 'droplet-'.Str::lower(Str::random(8)),
            'region'   => $this->option('region'),
            'size'     => $this->option('size'),
            'image'    => $this->option('image'),
            'ssh_keys' => [$sshKeyId],
        ]);

        $request = $factory
            ->createRequest('POST', self::DO_API_BASE.'/droplets')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$apiKey)
            ->withBody($factory->createStream($payload));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->error('HTTP request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);

        if ($status !== 202) {
            $message = $data['message'] ?? $body;
            $this->error("DigitalOcean API error [{$status}]: {$message}");

            return self::FAILURE;
        }

        $droplet = $data['droplet'];
        $this->info('Droplet created successfully.');
        $this->table(
            ['ID', 'Name', 'Region', 'Size', 'Status'],
            [[
                $droplet['id'],
                $droplet['name'],
                $droplet['region']['slug'] ?? $this->option('region'),
                $droplet['size_slug'] ?? $this->option('size'),
                $droplet['status'],
            ]]
        );

        $publicIp = $this->waitForPublicIp($droplet['id'], $apiKey, $factory);

        if ($publicIp === null) {
            $this->warn('Droplet is active but no public IP was assigned within the timeout.');

            return self::FAILURE;
        }

        $this->info("Public IP: {$publicIp}");

        return self::SUCCESS;
    }

    /**
     * Resolve the --public-key option to a DigitalOcean SSH key ID.
     *
     * Accepts:
     *   - A raw public key string (ssh-rsa / ssh-ed25519 / ecdsa-*): uploaded if not already present.
     *   - A key name registered on DigitalOcean: looked up and resolved to its numeric ID.
     *   - A numeric ID or fingerprint: returned as-is.
     */
    private function resolveSSHKey(string $value, string $apiKey, HttpFactory $factory): int|string|null
    {
        if ($this->isRawPublicKey($value)) {
            return $this->uploadOrFindKey($value, $apiKey, $factory);
        }

        $id = $this->findKeyIdByName($value, $apiKey, $factory);

        if ($id !== null) {
            return $id;
        }

        // Fall through: assume it is already a fingerprint or numeric ID.
        return $value;
    }

    private function isRawPublicKey(string $value): bool
    {
        return (bool) preg_match('/^(ssh-|ecdsa-|sk-ssh-)/i', trim($value));
    }

    private function uploadOrFindKey(string $publicKey, string $apiKey, HttpFactory $factory): ?int
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        $name  = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : 'key-'.Str::lower(Str::random(8));

        $payload = json_encode(['name' => $name, 'public_key' => trim($publicKey)]);

        $request = $factory
            ->createRequest('POST', self::DO_API_BASE.'/account/keys')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$apiKey)
            ->withBody($factory->createStream($payload));

        $response = $this->httpClient->sendRequest($request);
        $data     = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() === 201) {
            $this->line("SSH key <info>{$data['ssh_key']['name']}</info> uploaded to DigitalOcean.");

            return $data['ssh_key']['id'];
        }

        // Key already exists on DigitalOcean — find it by matching the public key blob.
        $trimmed = trim($publicKey);
        foreach ($this->listSSHKeys($apiKey, $factory) as $key) {
            if (trim($key['public_key']) === $trimmed) {
                $this->line("Using existing DigitalOcean SSH key <info>{$key['name']}</info>.");

                return $key['id'];
            }
        }

        return null;
    }

    private function findKeyIdByName(string $name, string $apiKey, HttpFactory $factory): ?int
    {
        foreach ($this->listSSHKeys($apiKey, $factory) as $key) {
            if ($key['name'] === $name) {
                $this->line("Using existing DigitalOcean SSH key <info>{$key['name']}</info>.");

                return $key['id'];
            }
        }

        return null;
    }

    /** @return array<int, array{id: int, name: string, public_key: string}> */
    private function listSSHKeys(string $apiKey, HttpFactory $factory): array
    {
        $request = $factory
            ->createRequest('GET', self::DO_API_BASE.'/account/keys')
            ->withHeader('Authorization', 'Bearer '.$apiKey);

        $response = $this->httpClient->sendRequest($request);
        $data     = json_decode((string) $response->getBody(), true);

        return $data['ssh_keys'] ?? [];
    }

    private function waitForPublicIp(int $dropletId, string $apiKey, HttpFactory $factory): ?string
    {
        $this->output->write('Waiting for public IP');

        for ($attempt = 0; $attempt < 30; $attempt++) {
            sleep(5);
            $this->output->write('.');

            $request = $factory
                ->createRequest('GET', self::DO_API_BASE."/droplets/{$dropletId}")
                ->withHeader('Authorization', 'Bearer '.$apiKey);

            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface) {
                continue;
            }

            $data = json_decode((string) $response->getBody(), true);

            $ip = collect($data['droplet']['networks']['v4'] ?? [])
                ->firstWhere('type', 'public')['ip_address'] ?? null;

            if ($ip !== null) {
                $this->newLine();

                return $ip;
            }
        }

        $this->newLine();

        return null;
    }
}

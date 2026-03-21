<?php

namespace App\Console\Commands;

use App\Services\Cloud\Hetzner\CreateHetznerServerData;
use App\Services\Cloud\Hetzner\HetznerServerCreator;
use App\Services\Cloud\Hetzner\HetznerServerException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateHetznerServer extends Command
{
    protected $signature = 'hetzner:server:create
                            {--key= : The API key}
                            {--name= : The server name (a random one will be generated if not provided)}
                            {--location=nbg1 : The Hetzner location}
                            {--type=cx23 : The Hetzner server type}
                            {--image=ubuntu-24.04 : The image}
                            {--public-key= : Name of an existing Hetzner SSH key, or a raw public key string (required)}';

    protected $description = 'Creates a Hetzner Cloud server';

    private const HETZNER_API_SECRET_PATH = '/run/secrets/hetzner_api_key';

    public function __construct(private readonly HetznerServerCreator $serverCreator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apiKey = $this->resolveApiKey();

        if (empty($apiKey)) {
            $this->error('An API key is required. Pass --key=<token>, provide it in /run/secrets/hetzner-api, or set HETZNER_TOKEN in your environment.');

            return self::FAILURE;
        }

        $publicKeyOption = $this->option('public-key');

        if (empty($publicKeyOption)) {
            $this->error('--public-key is required. Provide an existing Hetzner SSH key name or a raw public key string.');

            return self::FAILURE;
        }

        $this->info('Resolving SSH key…');

        try {
            $result = $this->serverCreator->create(new CreateHetznerServerData(
                apiKey: $apiKey,
                name: $this->option('name') ?? 'server-'.Str::lower(Str::random(8)),
                location: (string) $this->option('location'),
                serverType: (string) $this->option('type'),
                image: (string) $this->option('image'),
                publicKey: $publicKeyOption,
            ));
        } catch (HetznerServerException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->sshKeyStatus === 'uploaded' && $result->sshKeyName !== null) {
            $this->line("SSH key <info>{$result->sshKeyName}</info> uploaded to Hetzner.");
        }

        if ($result->sshKeyStatus === 'existing' && $result->sshKeyName !== null) {
            $this->line("Using existing Hetzner SSH key <info>{$result->sshKeyName}</info>.");
        }

        $this->info('Server created successfully.');
        $this->table(
            ['ID', 'Name', 'Location', 'Type', 'Status'],
            [[
                $result->serverId,
                $result->name,
                $result->location,
                $result->serverType,
                $result->status,
            ]]
        );

        if ($result->publicIp === null) {
            $this->warn('Server is active but no public IPv4 was assigned within the timeout.');

            return self::FAILURE;
        }

        $this->info("Public IP: {$result->publicIp}");

        return self::SUCCESS;
    }

    private function resolveApiKey(): ?string
    {
        $commandLineApiKey = $this->option('key');

        if (is_string($commandLineApiKey) && trim($commandLineApiKey) !== '') {
            return trim($commandLineApiKey);
        }

        $secretFileApiKey = $this->readApiKeyFromSecret();

        if ($secretFileApiKey !== null) {
            return $secretFileApiKey;
        }

        $configuredApiKey = config('services.hetzner.token');

        if (is_string($configuredApiKey) && trim($configuredApiKey) !== '') {
            return trim($configuredApiKey);
        }

        return null;
    }

    private function readApiKeyFromSecret(): ?string
    {
        if (! is_readable(self::HETZNER_API_SECRET_PATH)) {
            $this->info('Hetzner API not found in /run/secrets/hetzner-api.');

            return null;
        }

        $apiKey = file_get_contents(self::HETZNER_API_SECRET_PATH);

        if ($apiKey === false) {
            $this->info('Failed to read Hetzner API key from /run/secrets/hetzner-api.');

            return null;
        }

        $apiKey = trim($apiKey);

        return $apiKey !== '' ? $apiKey : null;
    }
}

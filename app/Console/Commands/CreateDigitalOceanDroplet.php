<?php

namespace App\Console\Commands;

use App\Services\DigitalOcean\CreateDigitalOceanDropletData;
use App\Services\DigitalOcean\DigitalOceanDropletCreator;
use App\Services\DigitalOcean\DigitalOceanDropletException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

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

    private const DO_API_SECRET_PATH = '/run/secrets/do-api';

    public function __construct(private readonly DigitalOceanDropletCreator $dropletCreator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apiKey = $this->resolveApiKey();

        if (empty($apiKey)) {
            $this->error('An API key is required. Pass --key=<token>, provide it in /run/secrets/do-api, or set DIGITALOCEAN_TOKEN in your environment.');

            return self::FAILURE;
        }

        $publicKeyOption = $this->option('public-key');

        if (empty($publicKeyOption)) {
            $this->error('--public-key is required. Provide an existing DigitalOcean key name or a raw public key string.');

            return self::FAILURE;
        }

        $this->info('Resolving SSH key…');

        try {
            $result = $this->dropletCreator->create(new CreateDigitalOceanDropletData(
                apiKey: $apiKey,
                name: $this->option('name') ?? 'droplet-'.Str::lower(Str::random(8)),
                region: (string) $this->option('region'),
                size: (string) $this->option('size'),
                image: (string) $this->option('image'),
                publicKey: $publicKeyOption,
            ));
        } catch (DigitalOceanDropletException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->sshKeyStatus === 'uploaded' && $result->sshKeyName !== null) {
            $this->line("SSH key <info>{$result->sshKeyName}</info> uploaded to DigitalOcean.");
        }

        if ($result->sshKeyStatus === 'existing' && $result->sshKeyName !== null) {
            $this->line("Using existing DigitalOcean SSH key <info>{$result->sshKeyName}</info>.");
        }

        $this->info('Droplet created successfully.');
        $this->table(
            ['ID', 'Name', 'Region', 'Size', 'Status'],
            [[
                $result->dropletId,
                $result->name,
                $result->region,
                $result->size,
                $result->status,
            ]]
        );

        if ($result->publicIp === null) {
            $this->warn('Droplet is active but no public IP was assigned within the timeout.');

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

        $configuredApiKey = config('services.digitalocean.token');

        if (is_string($configuredApiKey) && trim($configuredApiKey) !== '') {
            return trim($configuredApiKey);
        }

        return null;
    }

    private function readApiKeyFromSecret(): ?string
    {
        if (! is_readable(self::DO_API_SECRET_PATH)) {
            $this->info('DigitalOcean API not found in /run/secrets/do-api.');

            return null;
        }

        $apiKey = file_get_contents(self::DO_API_SECRET_PATH);

        if ($apiKey === false) {
            $this->info('Failed to read DigitalOcean API key from /run/secrets/do-api.');

            return null;
        }

        $apiKey = trim($apiKey);

        return $apiKey !== '' ? $apiKey : null;
    }
}

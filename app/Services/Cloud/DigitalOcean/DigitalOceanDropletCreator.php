<?php

namespace App\Services\Cloud\DigitalOcean;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class DigitalOceanDropletCreator
{
    private const API_BASE = 'https://api.digitalocean.com/v2';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly HttpFactory $httpFactory,
    ) {}

    public function create(CreateDigitalOceanDropletData $data): DigitalOceanDropletResult
    {
        $this->guardPollingConfiguration($data);

        $resolvedSshKey = $this->resolveSshKey($data->publicKey, $data->apiKey);

        $response = $this->sendRequest($this->dropletCreateRequest($data, $resolvedSshKey['id']));
        $status = $response->getStatusCode();
        $payload = $this->decodeResponse($response);

        if ($status !== 202) {
            $message = is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Unexpected response from the DigitalOcean API.';

            throw new DigitalOceanDropletException("DigitalOcean API error [{$status}]: {$message}");
        }

        $droplet = $payload['droplet'] ?? null;

        if (! is_array($droplet) || ! isset($droplet['id'], $droplet['name'], $droplet['status'])) {
            throw new DigitalOceanDropletException('DigitalOcean API response did not include droplet details.');
        }

        return new DigitalOceanDropletResult(
            dropletId: (int) $droplet['id'],
            name: (string) $droplet['name'],
            region: (string) ($droplet['region']['slug'] ?? $data->region),
            type: (string) ($droplet['size_slug'] ?? $data->serverType),
            status: (string) $droplet['status'],
            publicIp: $this->waitForPublicIp(
                dropletId: (int) $droplet['id'],
                apiKey: $data->apiKey,
                attempts: $data->publicIpPollAttempts,
                intervalSeconds: $data->publicIpPollIntervalSeconds,
            ),
            sshKeyId: $resolvedSshKey['id'],
            sshKeyName: $resolvedSshKey['name'],
            sshKeyStatus: $resolvedSshKey['status'],
        );
    }

    private function dropletCreateRequest(CreateDigitalOceanDropletData $data, int|string $sshKeyId): RequestInterface
    {
        $payload = $this->encodePayload([
            'name' => $data->name,
            'region' => $data->region,
            'size' => $data->serverType,
            'image' => $data->image,
            'ssh_keys' => [$sshKeyId],
        ]);

        return $this->authorizedRequest('POST', '/droplets', $data->apiKey, $payload);
    }

    /**
     * @return array{id: int|string, name: string|null, status: string}
     */
    private function resolveSshKey(string $value, string $apiKey): array
    {
        if ($this->isRawPublicKey($value)) {
            return $this->uploadOrFindKey($value, $apiKey);
        }

        $id = $this->findKeyIdByName($value, $apiKey);

        if ($id !== null) {
            return [
                'id' => $id,
                'name' => $value,
                'status' => 'existing',
            ];
        }

        return [
            'id' => $value,
            'name' => null,
            'status' => 'provided',
        ];
    }

    private function isRawPublicKey(string $value): bool
    {
        return (bool) preg_match('/^(ssh-|ecdsa-|sk-ssh-)/i', trim($value));
    }

    /**
     * @return array{id: int, name: string, status: string}
     */
    private function uploadOrFindKey(string $publicKey, string $apiKey): array
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        $name = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : 'key-'.Str::lower(Str::random(8));

        $payload = $this->encodePayload([
            'name' => $name,
            'public_key' => trim($publicKey),
        ]);

        $response = $this->sendRequest($this->authorizedRequest('POST', '/account/keys', $apiKey, $payload));
        $data = $this->decodeResponse($response);

        if ($response->getStatusCode() === 201) {
            return [
                'id' => (int) $data['ssh_key']['id'],
                'name' => (string) $data['ssh_key']['name'],
                'status' => 'uploaded',
            ];
        }

        $trimmedPublicKey = trim($publicKey);

        foreach ($this->listSshKeys($apiKey) as $key) {
            if (trim($key['public_key']) === $trimmedPublicKey) {
                return [
                    'id' => $key['id'],
                    'name' => $key['name'],
                    'status' => 'existing',
                ];
            }
        }

        throw new DigitalOceanDropletException('Could not find or upload the provided SSH key.');
    }

    private function findKeyIdByName(string $name, string $apiKey): ?int
    {
        foreach ($this->listSshKeys($apiKey) as $key) {
            if ($key['name'] === $name) {
                return $key['id'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id: int, name: string, public_key: string}>
     */
    private function listSshKeys(string $apiKey): array
    {
        $response = $this->sendRequest($this->authorizedRequest('GET', '/account/keys', $apiKey));
        $data = $this->decodeResponse($response);

        return collect($data['ssh_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_array($key))
            ->map(fn (array $key): array => [
                'id' => (int) ($key['id'] ?? 0),
                'name' => (string) ($key['name'] ?? ''),
                'public_key' => (string) ($key['public_key'] ?? ''),
            ])
            ->all();
    }

    private function waitForPublicIp(int $dropletId, string $apiKey, int $attempts, int $intervalSeconds): ?string
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            sleep($intervalSeconds);

            try {
                $response = $this->sendRequest($this->authorizedRequest('GET', "/droplets/{$dropletId}", $apiKey));
            } catch (DigitalOceanDropletException $exception) {
                $lastException = $exception;

                continue;
            }

            $payload = $this->decodeResponse($response);
            $publicNetwork = collect($payload['droplet']['networks']['v4'] ?? [])
                ->firstWhere('type', 'public');
            $publicIp = is_array($publicNetwork) ? ($publicNetwork['ip_address'] ?? null) : null;

            if (is_string($publicIp) && $publicIp !== '') {
                return $publicIp;
            }
        }

        if ($lastException !== null) {
            throw new DigitalOceanDropletException(
                'Failed while waiting for the droplet public IP.',
                previous: $lastException,
            );
        }

        return null;
    }

    private function authorizedRequest(string $method, string $path, string $apiKey, ?string $payload = null): RequestInterface
    {
        $request = $this->httpFactory
            ->createRequest($method, self::API_BASE.$path)
            ->withHeader('Authorization', 'Bearer '.$apiKey);

        if ($payload === null) {
            return $request;
        }

        return $request
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->httpFactory->createStream($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        $encodedPayload = json_encode($payload);

        if (! is_string($encodedPayload)) {
            throw new DigitalOceanDropletException('Failed to encode the DigitalOcean API request payload.');
        }

        return $encodedPayload;
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new DigitalOceanDropletException('HTTP request failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new DigitalOceanDropletException('DigitalOcean API returned an invalid JSON response.');
        }

        return $decoded;
    }

    private function guardPollingConfiguration(CreateDigitalOceanDropletData $data): void
    {
        if ($data->publicIpPollAttempts < 1) {
            throw new DigitalOceanDropletException('Public IP poll attempts must be greater than zero.');
        }

        if ($data->publicIpPollIntervalSeconds < 1) {
            throw new DigitalOceanDropletException('Public IP poll interval must be greater than zero.');
        }
    }
}

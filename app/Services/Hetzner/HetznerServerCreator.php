<?php

namespace App\Services\Hetzner;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HetznerServerCreator
{
    private const API_BASE = 'https://api.hetzner.cloud/v1';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly HttpFactory $httpFactory,
    ) {}

    public function create(CreateHetznerServerData $data): HetznerServerResult
    {
        $this->guardPollingConfiguration($data);

        $resolvedSshKey = $this->resolveSshKey($data->publicKey, $data->apiKey);

        $response = $this->sendRequest($this->serverCreateRequest($data, $resolvedSshKey['id']));
        $status = $response->getStatusCode();
        $payload = $this->decodeResponse($response);

        if ($status !== 201) {
            $message = is_string($payload['error']['message'] ?? null)
                ? $payload['error']['message']
                : (is_string($payload['message'] ?? null) ? $payload['message'] : 'Unexpected response from the Hetzner API.');

            throw new HetznerServerException("Hetzner API error [{$status}]: {$message}");
        }

        $server = $payload['server'] ?? null;

        if (! is_array($server) || ! isset($server['id'], $server['name'], $server['status'])) {
            throw new HetznerServerException('Hetzner API response did not include server details.');
        }

        return new HetznerServerResult(
            serverId: (int) $server['id'],
            name: (string) $server['name'],
            location: (string) ($server['datacenter']['location']['name'] ?? $server['datacenter']['location']['description'] ?? $data->location),
            serverType: (string) ($server['server_type']['name'] ?? $data->serverType),
            status: (string) $server['status'],
            publicIp: $this->waitForPublicIp(
                serverId: (int) $server['id'],
                apiKey: $data->apiKey,
                attempts: $data->publicIpPollAttempts,
                intervalSeconds: $data->publicIpPollIntervalSeconds,
            ),
            sshKeyId: $resolvedSshKey['id'],
            sshKeyName: $resolvedSshKey['name'],
            sshKeyStatus: $resolvedSshKey['status'],
        );
    }

    private function serverCreateRequest(CreateHetznerServerData $data, int|string $sshKeyId): RequestInterface
    {
        $payload = $this->encodePayload([
            'name' => $data->name,
            'location' => $data->location,
            'server_type' => $data->serverType,
            'image' => $data->image,
            'ssh_keys' => [$sshKeyId],
            'public_net' => [
                'ipv4_enabled' => $data->enableIpv4,
                'ipv6_enabled' => $data->enableIpv6,
            ],
        ]);

        return $this->authorizedRequest('POST', '/servers', $data->apiKey, $payload);
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

        $response = $this->sendRequest($this->authorizedRequest('POST', '/ssh_keys', $apiKey, $payload));
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

        throw new HetznerServerException('Could not find or upload the provided SSH key.');
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
        $response = $this->sendRequest($this->authorizedRequest('GET', '/ssh_keys', $apiKey));
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

    private function waitForPublicIp(int $serverId, string $apiKey, int $attempts, int $intervalSeconds): ?string
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            sleep($intervalSeconds);

            try {
                $response = $this->sendRequest($this->authorizedRequest('GET', "/servers/{$serverId}", $apiKey));
            } catch (HetznerServerException $exception) {
                $lastException = $exception;

                continue;
            }

            $payload = $this->decodeResponse($response);
            $publicIp = $payload['server']['public_net']['ipv4']['ip'] ?? null;

            if (is_string($publicIp) && $publicIp !== '') {
                return $publicIp;
            }
        }

        if ($lastException !== null) {
            throw new HetznerServerException(
                'Failed while waiting for the server public IP.',
                previous: $lastException,
            );
        }

        return null;
    }

    private function authorizedRequest(string $method, string $path, string $apiKey, ?string $payload = null): RequestInterface
    {
        $request = $this->httpFactory
            ->createRequest($method, self::API_BASE.$path)
            ->withHeader('Authorization', 'Bearer '.$apiKey)
            ->withHeader('Content-Type', 'application/json');

        if ($payload === null) {
            return $request;
        }

        return $request->withBody($this->httpFactory->createStream($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        $encodedPayload = json_encode($payload);

        if (! is_string($encodedPayload)) {
            throw new HetznerServerException('Failed to encode the Hetzner API request payload.');
        }

        return $encodedPayload;
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new HetznerServerException('HTTP request failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new HetznerServerException('Hetzner API returned an invalid JSON response.');
        }

        return $decoded;
    }

    private function guardPollingConfiguration(CreateHetznerServerData $data): void
    {
        if ($data->publicIpPollAttempts < 1) {
            throw new HetznerServerException('Public IP poll attempts must be greater than zero.');
        }

        if ($data->publicIpPollIntervalSeconds < 1) {
            throw new HetznerServerException('Public IP poll interval must be greater than zero.');
        }
    }
}

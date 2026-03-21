<?php

namespace App\Services\Cloudflare;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class CloudflareService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly HttpFactory $httpFactory,
        /** @var array{type: string, token?: string, email?: string, key?: string} */
        private readonly array $credentials,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function getZones(): array
    {
        $response = $this->sendRequest($this->makeRequest('GET', '/zones?per_page=50'));

        return $this->decodeResponse($response)['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getZone(string $zoneId): array
    {
        $response = $this->sendRequest($this->makeRequest('GET', "/zones/{$zoneId}"));

        return $this->decodeResponse($response)['result'] ?? [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getDnsRecords(string $zoneId): array
    {
        $response = $this->sendRequest($this->makeRequest('GET', "/zones/{$zoneId}/dns_records?per_page=100"));

        return $this->decodeResponse($response)['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getZoneAnalytics(string $zoneId): array
    {
        $since = now()->subDays(7)->toIso8601String();
        $until = now()->toIso8601String();

        $response = $this->sendRequest(
            $this->makeRequest('GET', "/zones/{$zoneId}/analytics/dashboard?since={$since}&until={$until}&continuous=true")
        );

        return $this->decodeResponse($response)['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSslSettings(string $zoneId): array
    {
        $response = $this->sendRequest($this->makeRequest('GET', "/zones/{$zoneId}/settings/ssl"));

        return $this->decodeResponse($response)['result'] ?? [];
    }

    public function purgeCache(string $zoneId): bool
    {
        $body = $this->httpFactory->createStream(json_encode(['purge_everything' => true]));

        $response = $this->sendRequest(
            $this->makeRequest('POST', "/zones/{$zoneId}/purge_cache")
                ->withBody($body)
                ->withHeader('Content-Type', 'application/json')
        );

        $payload = $this->decodeResponse($response);

        return (bool) ($payload['success'] ?? false);
    }

    /**
     * Create a new DNS record in the given zone.
     *
     * @return array<string, mixed>
     */
    public function createDnsRecord(
        string $zoneId,
        string $type,
        string $name,
        string $content,
        bool $proxied = false,
        int $ttl = 1,
    ): array {
        $body = $this->httpFactory->createStream(json_encode([
            'type' => strtoupper($type),
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied,
            'ttl' => $ttl,
        ]));

        $response = $this->sendRequest(
            $this->makeRequest('POST', "/zones/{$zoneId}/dns_records")
                ->withBody($body)
                ->withHeader('Content-Type', 'application/json')
        );

        return $this->decodeResponse($response)['result'] ?? [];
    }

    /**
     * Update an existing DNS record.
     *
     * @return array<string, mixed>
     */
    public function updateDnsRecord(
        string $zoneId,
        string $recordId,
        string $type,
        string $name,
        string $content,
        bool $proxied = false,
        int $ttl = 1,
    ): array {
        $body = $this->httpFactory->createStream(json_encode([
            'type' => strtoupper($type),
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied,
            'ttl' => $ttl,
        ]));

        $response = $this->sendRequest(
            $this->makeRequest('PATCH', "/zones/{$zoneId}/dns_records/{$recordId}")
                ->withBody($body)
                ->withHeader('Content-Type', 'application/json')
        );

        return $this->decodeResponse($response)['result'] ?? [];
    }

    /**
     * Delete a DNS record and return whether it succeeded.
     */
    public function deleteDnsRecord(string $zoneId, string $recordId): bool
    {
        $response = $this->sendRequest(
            $this->makeRequest('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}")
        );

        $payload = $this->decodeResponse($response);

        return isset($payload['result']['id']);
    }

    /**
     * Find the Cloudflare zone that owns the given fully-qualified domain name.
     * Extracts the apex domain from the FQDN and matches it against the
     * account's zones.
     *
     * @return array<string, mixed>|null
     */
    public function findZoneForDomain(string $domain): ?array
    {
        $domain = strtolower(rtrim($domain, '.'));
        $parts = explode('.', $domain);

        // Build apex candidates from shortest to longest match (e.g. "foo.com", "bar.foo.com")
        $candidates = [];
        for ($i = count($parts) - 2; $i >= 0; $i--) {
            $candidates[] = implode('.', array_slice($parts, $i));
        }

        $zones = $this->getZones();

        foreach ($candidates as $candidate) {
            foreach ($zones as $zone) {
                if (isset($zone['name']) && $zone['name'] === $candidate) {
                    return $zone;
                }
            }
        }

        return null;
    }

    private function makeRequest(string $method, string $path): RequestInterface
    {
        $request = $this->httpFactory->createRequest($method, self::API_BASE.$path);

        if ($this->credentials['type'] === 'api_token') {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->credentials['token']);
        } else {
            $request = $request
                ->withHeader('X-Auth-Email', $this->credentials['email'])
                ->withHeader('X-Auth-Key', $this->credentials['key']);
        }

        return $request->withHeader('Content-Type', 'application/json');
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->httpClient->sendRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}

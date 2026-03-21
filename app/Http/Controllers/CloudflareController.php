<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\Cloudflare\CloudflareService;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Http\Client\ClientInterface;

class CloudflareController extends Controller
{
    /**
     * List all Cloudflare integrations the user has configured.
     */
    public function index(Request $request): Response
    {
        $integrations = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->orderBy('name')
            ->get(['id', 'name', 'provider', 'created_at']);

        return Inertia::render('cloudflare/Index', [
            'integrations' => $integrations,
        ]);
    }

    /**
     * List all zones for a specific Cloudflare integration.
     */
    public function zones(Request $request, int $integrationId): Response|RedirectResponse
    {
        $integration = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->findOrFail($integrationId);

        $service = $this->buildService($integration->credentials);
        $zones = $service->getZones();

        return Inertia::render('cloudflare/Zones', [
            'integration' => ['id' => $integration->id, 'name' => $integration->name],
            'zones' => $zones,
        ]);
    }

    /**
     * Show DNS, analytics, and settings for a specific zone.
     */
    public function show(Request $request, int $integrationId, string $zoneId): Response|RedirectResponse
    {
        $integration = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->findOrFail($integrationId);

        $service = $this->buildService($integration->credentials);

        [$zone, $dnsRecords, $analytics, $sslSettings] = [
            $service->getZone($zoneId),
            $service->getDnsRecords($zoneId),
            $service->getZoneAnalytics($zoneId),
            $service->getSslSettings($zoneId),
        ];

        return Inertia::render('cloudflare/Show', [
            'integration' => ['id' => $integration->id, 'name' => $integration->name],
            'zoneId' => $zoneId,
            'zoneName' => $zone['name'] ?? $zoneId,
            'dnsRecords' => $dnsRecords,
            'analytics' => $analytics,
            'sslSettings' => $sslSettings,
        ]);
    }

    /**
     * @param  array{type: string, token?: string, email?: string, key?: string}  $credentials
     */
    private function buildService(array $credentials): CloudflareService
    {
        return new CloudflareService(
            httpClient: app(ClientInterface::class),
            httpFactory: new HttpFactory,
            credentials: $credentials,
        );
    }
}

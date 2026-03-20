<?php

namespace App\Http\Controllers;

use App\Services\Cloudflare\CloudflareService;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Psr\Http\Client\ClientInterface;

class CloudflarePurgeCacheController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, int $integrationId, string $zoneId): RedirectResponse
    {
        $integration = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->findOrFail($integrationId);

        $service = new CloudflareService(
            httpClient: app(ClientInterface::class),
            httpFactory: new HttpFactory,
            credentials: $integration->credentials,
        );

        $service->purgeCache($zoneId);

        return back()->with('status', 'cache-purged');
    }
}

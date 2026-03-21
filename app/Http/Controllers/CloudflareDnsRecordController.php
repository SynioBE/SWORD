<?php

namespace App\Http\Controllers;

use App\Actions\Cloudflare\UpsertCloudflareDnsRecord;
use App\Http\Requests\Cloudflare\StoreDnsRecordRequest;
use App\Http\Requests\Cloudflare\UpdateDnsRecordRequest;
use App\Services\Cloudflare\CloudflareService;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Psr\Http\Client\ClientInterface;

class CloudflareDnsRecordController extends Controller
{
    /**
     * Create or update a DNS record in the specified zone.
     */
    public function store(
        StoreDnsRecordRequest $request,
        int $integrationId,
        string $zoneId,
    ): RedirectResponse {
        $integration = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->findOrFail($integrationId);

        $service = $this->buildService($integration->credentials);

        (new UpsertCloudflareDnsRecord)->handle(
            service: $service,
            zoneId: $zoneId,
            name: $request->string('name')->toString(),
            type: $request->string('type')->toString(),
            content: $request->string('content')->toString(),
            proxied: (bool) $request->input('proxied', false),
            ttl: (int) $request->input('ttl', 1),
            cnameContent: $request->input('cname_content'),
        );

        return back()->with('status', 'dns-record-upserted');
    }

    /**
     * Update an existing DNS record by its ID.
     */
    public function update(
        UpdateDnsRecordRequest $request,
        int $integrationId,
        string $zoneId,
        string $recordId,
    ): RedirectResponse {
        $integration = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->findOrFail($integrationId);

        $service = $this->buildService($integration->credentials);

        $service->updateDnsRecord(
            zoneId: $zoneId,
            recordId: $recordId,
            type: $request->string('type')->toString(),
            name: $request->string('name')->toString(),
            content: $request->string('content')->toString(),
            proxied: (bool) $request->input('proxied', false),
            ttl: (int) $request->input('ttl', 1),
        );

        return back()->with('status', 'dns-record-updated');
    }

    /**
     * Delete a DNS record from the specified zone.
     */
    public function destroy(
        Request $request,
        int $integrationId,
        string $zoneId,
        string $recordId,
    ): RedirectResponse {
        $integration = $request->user()
            ->integrations()
            ->where('provider', 'cloudflare')
            ->findOrFail($integrationId);

        $service = $this->buildService($integration->credentials);
        $service->deleteDnsRecord($zoneId, $recordId);

        return back()->with('status', 'dns-record-deleted');
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

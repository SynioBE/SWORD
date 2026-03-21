<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreIntegrationRequest;
use App\Http\Requests\Settings\UpdateIntegrationsRequest;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsController extends Controller
{
    public function index(Request $request): Response
    {
        $integrations = $request->user()
            ->integrations()
            ->orderBy('provider')
            ->orderBy('name')
            ->get()
            ->map(fn (Integration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'provider' => $i->provider,
                'credentials' => $this->maskCredentials($i->credentials),
                'created_at' => $i->created_at,
            ]);

        return Inertia::render('settings/Integrations', [
            'integrations' => $integrations,
        ]);
    }

    public function store(StoreIntegrationRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->integrations()->create([
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'credentials' => $this->buildCredentials($validated),
        ]);

        return to_route('integrations.index');
    }

    public function update(UpdateIntegrationsRequest $request, int $integrationId): RedirectResponse
    {
        $integration = $request->user()->integrations()->findOrFail($integrationId);

        $validated = $request->validated();

        $credentials = ['type' => $validated['type']];

        if ($validated['type'] === 'api_token') {
            $credentials['token'] = filled($validated['token'])
                ? $validated['token']
                : $integration->credentials['token'];
        } else {
            $credentials['email'] = filled($validated['email'])
                ? $validated['email']
                : $integration->credentials['email'];
            $credentials['key'] = filled($validated['key'])
                ? $validated['key']
                : $integration->credentials['key'];
        }

        $integration->update([
            'name' => $validated['name'],
            'credentials' => $credentials,
        ]);

        return to_route('integrations.index');
    }

    public function destroy(Request $request, int $integrationId): RedirectResponse
    {
        $request->user()->integrations()->findOrFail($integrationId)->delete();

        return to_route('integrations.index');
    }

    /**
     * @param  array{type: string, token?: string|null, email?: string|null, key?: string|null}  $validated
     * @return array{type: string, token?: string, email?: string, key?: string}
     */
    private function buildCredentials(array $validated): array
    {
        $credentials = ['type' => $validated['type']];

        if ($validated['type'] === 'api_token') {
            $credentials['token'] = $validated['token'];
        } else {
            $credentials['email'] = $validated['email'];
            $credentials['key'] = $validated['key'];
        }

        return $credentials;
    }

    /**
     * @param  array{type: string, token?: string, email?: string, key?: string}  $credentials
     * @return array{type: string, token?: string|null, email?: string|null, key?: string|null}
     */
    private function maskCredentials(array $credentials): array
    {
        return [
            'type' => $credentials['type'],
            'token' => isset($credentials['token'])
                ? str_repeat('*', 28).substr($credentials['token'], -4)
                : null,
            'email' => $credentials['email'] ?? null,
            'key' => isset($credentials['key'])
                ? str_repeat('*', 28).substr($credentials['key'], -4)
                : null,
        ];
    }
}

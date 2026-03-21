<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sites\StoreSiteRequest;
use App\Http\Resources\SiteResource;
use App\Jobs\InstallSiteJob;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(Request $request): Response
    {
        $sites = $request->user()
            ->sites()
            ->with('server')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Site $site) => (new SiteResource($site))->toArray($request));

        $servers = $request->user()
            ->servers()
            ->where('status', 'provisioned')
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address']);

        return Inertia::render('sites/Index', [
            'sites' => $sites,
            'servers' => $servers,
        ]);
    }

    public function store(StoreSiteRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $domain = $validated['domain'];
        $dbSlug = preg_replace('/[^a-z0-9]/', '_', strtolower($domain));
        $dbSlug = substr($dbSlug, 0, 48);

        $site = $request->user()->sites()->create([
            'server_id' => $validated['server_id'],
            'domain' => $domain,
            'php_version' => $validated['php_version'],
            'db_name' => $dbSlug,
            'db_user' => $dbSlug,
            'db_password' => Str::random(24),
        ]);

        InstallSiteJob::dispatch($site);

        return redirect()->route('sites.show', $site);
    }

    public function show(Request $request, Site $site): Response
    {
        abort_unless($site->user_id === $request->user()->id, 403);

        $site->load('server:id,name,ip_address');

        return Inertia::render('sites/Show', [
            'site' => array_merge((new SiteResource($site))->resolve(), [
                'callback_signature' => $site->callback_signature,
            ]),
        ]);
    }

    public function installScript(Request $request, Site $site): \Illuminate\Http\Response
    {
        abort_unless($request->query('token') === $site->install_token, 403);

        $script = view('server-scripts.sites.create-wp', [
            'site' => $site,
            'server' => $site->server,
            'callbackUrl' => route('sites.callbacks.install', [
                'site' => $site->id,
                'signature' => $site->callback_signature,
            ]),
        ])->render();

        return response($script, 200, ['Content-Type' => 'text/x-shellscript']);
    }
}

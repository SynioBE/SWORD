<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteRequest;
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
            ->with('server:id,name,ip_address')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'domain' => $site->domain,
                'php_version' => $site->php_version,
                'status' => $site->status,
                'current_step' => $site->current_step,
                'installed_at' => $site->installed_at?->toIso8601String(),
                'created_at' => $site->created_at->toIso8601String(),
                'server' => [
                    'id' => $site->server->id,
                    'name' => $site->server->name,
                    'ip_address' => $site->server->ip_address,
                ],
            ]);

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
            'db_user' => $dbSlug.'_usr',
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
            'site' => [
                'id' => $site->id,
                'domain' => $site->domain,
                'php_version' => $site->php_version,
                'db_name' => $site->db_name,
                'status' => $site->status,
                'current_step' => $site->current_step,
                'install_log' => $site->install_log ?? [],
                'installed_at' => $site->installed_at?->toIso8601String(),
                'created_at' => $site->created_at->toIso8601String(),
                'server' => [
                    'id' => $site->server->id,
                    'name' => $site->server->name,
                    'ip_address' => $site->server->ip_address,
                ],
            ],
        ]);
    }

    public function installScript(Request $request, Site $site): \Illuminate\Http\Response
    {
        abort_unless($request->query('token') === $site->install_token, 403);

        $script = view('install-site', [
            'site' => $site,
            'server' => $site->server,
            'callbackUrl' => route('sites.install-callback', [
                'site' => $site->id,
                'signature' => $site->callback_signature,
            ]),
        ])->render();

        return response($script, 200, ['Content-Type' => 'text/x-shellscript']);
    }
}

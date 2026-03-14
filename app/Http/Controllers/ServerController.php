<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServerRequest;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function index(Request $request): Response
    {
        $servers = $request->user()
            ->servers()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'ip_address' => $server->ip_address,
                'provider' => $server->provider,
                'region' => $server->region,
                'status' => $server->status,
                'current_step' => $server->current_step,
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                'created_at' => $server->created_at->toIso8601String(),
            ]);

        return Inertia::render('servers/Index', [
            'servers' => $servers,
        ]);
    }

    public function store(StoreServerRequest $request): RedirectResponse
    {
        $server = $request->user()->servers()->create($request->validated());

        return redirect()->route('servers.show', $server);
    }

    public function show(Request $request, Server $server): Response
    {
        abort_unless($server->user_id === $request->user()->id, 403);

        $provisionUrl = route('servers.provision-script', [
            'server' => $server->id,
            'token' => $server->provision_token,
        ]);

        $wgetCommand = sprintf(
            'wget -qO sword-provision.sh "%s" && sudo bash sword-provision.sh 2>&1 | tee sword-provision.log',
            $provisionUrl,
        );

        return Inertia::render('servers/Show', [
            'server' => [
                'id' => $server->id,
                'name' => $server->name,
                'ip_address' => $server->ip_address,
                'provider' => $server->provider,
                'region' => $server->region,
                'status' => $server->status,
                'current_step' => $server->current_step,
                'provision_log' => $server->provision_log ?? [],
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                'created_at' => $server->created_at->toIso8601String(),
                'wget_command' => $wgetCommand,
                'callback_signature' => $server->callback_signature,
            ],
        ]);
    }

    public function provisionScript(Request $request, Server $server): \Illuminate\Http\Response
    {
        abort_unless($request->query('token') === $server->provision_token, 403);

        $script = view('provision-script', [
            'server' => $server,
            'callbackUrl' => route('servers.provision-callback', [
                'server' => $server->id,
                'signature' => $server->callback_signature,
            ]),
        ])->render();

        return response($script, 200, ['Content-Type' => 'text/x-shellscript']);
    }
}

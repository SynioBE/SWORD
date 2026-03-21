<?php

namespace App\Http\Controllers;

use App\Http\Requests\Servers\StoreServerRequest;
use App\Http\Resources\ServerResource;
use App\Jobs\RunAnsible;
use App\Models\BackupDestination;
use App\Models\Server;
use App\Services\ServerNameGenerator;
use Illuminate\Http\JsonResponse;
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
            ->map(fn (Server $server) => (new ServerResource($server))->toArray($request));

        return Inertia::render('servers/Index', [
            'servers' => $servers,
        ]);
    }

    public function generateName(ServerNameGenerator $generator): JsonResponse
    {
        $name = $generator->generate();

        return response()->json([
            'name' => $name,
            'hostname' => $generator->toHostname($name),
        ]);
    }

    public function store(StoreServerRequest $request): RedirectResponse
    {
        $server = $request->user()->servers()->create($request->validated());

        // @TODO This will be a problem, if it's executed before adding the public key.
        dispatch(new RunAnsible($server->id));

        return redirect()->route('servers.show', $server);
    }

    public function show(Request $request, Server $server): Response
    {
        abort_unless($server->user_id === $request->user()->id, 403);

        $provisionUrl = rtrim(config('app.url'), '/').route('servers.scripts.provision', [
            'server' => $server->id,
            'token' => $server->provision_token,
        ], false);

        $wgetCommand = sprintf(
            'wget -qO sword-provision.sh "%s" && sudo bash sword-provision.sh 2>&1 | tee sword-provision.log',
            $provisionUrl,
        );

        $backupSchedules = $server->backupSchedules()
            ->with(['backupDestination', 'latestBackupRun'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($schedule) => [
                'id' => $schedule->id,
                'backup_destination_id' => $schedule->backup_destination_id,
                'destination_name' => $schedule->backupDestination->name,
                'frequency' => $schedule->frequency,
                'time' => $schedule->time,
                'day_of_week' => $schedule->day_of_week,
                'day_of_month' => $schedule->day_of_month,
                'retention_count' => $schedule->retention_count,
                'is_enabled' => $schedule->is_enabled,
                'created_at' => $schedule->created_at->toIso8601String(),
                'last_run' => $schedule->latestBackupRun?->only([
                    'id', 'status', 'archive_name', 'size_bytes', 'duration_seconds', 'completed_at',
                ]),
            ]);

        $backupDestinations = $request->user()
            ->backupDestinations()
            ->orderBy('name')
            ->get()
            ->map(fn (BackupDestination $d) => [
                'id' => $d->id,
                'name' => $d->name,
            ]);

        $backupRuns = $server->backupRuns()
            ->with('backupDestination')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'destination_name' => $run->backupDestination->name,
                'status' => $run->status,
                'archive_name' => $run->archive_name,
                'size_bytes' => $run->size_bytes,
                'duration_seconds' => $run->duration_seconds,
                'started_at' => $run->started_at?->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
                'created_at' => $run->created_at->toIso8601String(),
            ]);

        return Inertia::render('servers/Show', [
            'server' => array_merge((new ServerResource($server))->resolve(), [
                'callback_signature' => $server->callback_signature,
                'wget_command' => $wgetCommand,
            ]),
            'backupSchedules' => $backupSchedules,
            'backupDestinations' => $backupDestinations,
            'backupRuns' => $backupRuns,
        ]);
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 403);

        $server->delete();

        return redirect()->route('servers.index');
    }

    public function provisionScript(Request $request, Server $server): \Illuminate\Http\Response
    {
        abort_unless($request->query('token') === $server->provision_token, 403);

        $script = view('server-scripts.servers.provision', [
            'server' => $server,
            'callbackUrl' => route('servers.callbacks.provision', [
                'server' => $server->id,
                'signature' => $server->callback_signature,
            ]),
        ])->render();

        return response($script, 200, ['Content-Type' => 'text/x-shellscript']);
    }
}

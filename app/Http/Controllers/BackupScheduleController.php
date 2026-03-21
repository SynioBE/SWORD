<?php

namespace App\Http\Controllers;

use App\Http\Requests\BackupSchedules\StoreBackupScheduleRequest;
use App\Jobs\RunBackupJob;
use App\Models\BackupSchedule;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BackupScheduleController extends Controller
{
    public function store(StoreBackupScheduleRequest $request, Server $server): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 403);

        $server->backupSchedules()->create($request->validated());

        return redirect()->route('servers.show', $server);
    }

    public function destroy(Request $request, Server $server, BackupSchedule $backupSchedule): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 403);
        abort_unless($backupSchedule->server_id === $server->id, 404);

        $backupSchedule->delete();

        return redirect()->route('servers.show', $server);
    }

    public function run(Request $request, Server $server, BackupSchedule $backupSchedule): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 403);
        abort_unless($backupSchedule->server_id === $server->id, 404);

        RunBackupJob::dispatch($backupSchedule);

        return redirect()->route('servers.show', $server);
    }
}

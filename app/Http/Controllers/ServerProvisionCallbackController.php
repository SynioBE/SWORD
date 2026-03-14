<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerProvisionCallbackController extends Controller
{
    public function __invoke(Request $request, Server $server): JsonResponse
    {
        abort_unless($request->query('signature') === $server->callback_signature, 403);

        $status = $request->input('status', 'provisioning');
        $step = $request->input('step');

        $log = $server->provision_log ?? [];

        if ($step) {
            $log[] = [
                'step' => $step,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $updates = [
            'provision_log' => $log,
            'status' => $status,
        ];

        if ($step) {
            $updates['current_step'] = $step;
        }

        if ($status === 'provisioned') {
            $updates['provisioned_at'] = now();
            $updates['current_step'] = null;
        }

        $server->update($updates);

        return response()->json(['ok' => true]);
    }
}

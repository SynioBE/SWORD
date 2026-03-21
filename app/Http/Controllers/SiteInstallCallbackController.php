<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteInstallCallbackController extends Controller
{
    public function __invoke(Request $request, Site $site): JsonResponse
    {
        abort_unless($request->query('signature') === $site->callback_signature, 403);

        $status = $request->input('status', 'installing');
        $step = $request->input('step');

        $log = $site->install_log ?? [];

        if ($step) {
            $log[] = [
                'step' => $step,
                'timestamp' => now()->toIso8601String(),
            ];
        }

        $updates = [
            'install_log' => $log,
            'status' => $status,
        ];

        if ($step) {
            $updates['current_step'] = $step;
        }

        if ($status === 'installed') {
            $updates['installed_at'] = now();
            $updates['current_step'] = null;
        }

        $site->update($updates);

        return response()->json(['ok' => true]);
    }
}

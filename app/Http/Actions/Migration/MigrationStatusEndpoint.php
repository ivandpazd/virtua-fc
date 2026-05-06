<?php

namespace App\Http\Actions\Migration;

use App\Modules\Migration\Services\MigrationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import side. GET /migration/status — returns the current user's migration
 * status and the latest progress checkpoint. Polled by the progress page.
 */
class MigrationStatusEndpoint
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $progress = MigrationProgress::get($user->id);

        return response()->json([
            'status' => $user->migration_status->value,
            'completed_at' => $user->migration_completed_at?->toAtomString(),
            'progress' => $progress,
        ]);
    }
}

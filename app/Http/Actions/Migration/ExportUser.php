<?php

namespace App\Http\Actions\Migration;

use App\Models\User;
use App\Modules\Migration\MigrationStatus;
use App\Modules\Migration\Services\UserExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Export side, server-to-server endpoint. Two modes via query string:
 *
 *   GET /api/migration/export                    → manifest (control plane + game ids)
 *   GET /api/migration/export?game_id=<uuid>     → one game's tables
 *
 * The two-mode shape lets the import side stream games one at a time so peak
 * memory on both sides stays bounded regardless of how many seasons the user
 * has played.
 *
 * The middleware (migration.s2s:s2s_export) guarantees the token is valid and
 * has set `migration_user_id` on the request.
 *
 * Only users in PENDING status are exportable. Once the import side seals
 * (status → COMPLETED), or after a previous export failed and was marked
 * FAILED, the export endpoint refuses — re-exporting in either case would let
 * a stale or buggy import side overwrite the prod tenant.
 */
class ExportUser
{
    public function __construct(
        private readonly UserExporter $exporter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('migration_user_id');

        $user = User::findOrFail($userId);
        if ($user->migration_status !== MigrationStatus::PENDING) {
            return response()->json([
                'error' => 'not_exportable',
                'migration_status' => $user->migration_status->value,
            ], 409);
        }

        $gameId = (string) $request->query('game_id', '');
        if ($gameId !== '') {
            if (! Str::isUuid($gameId)) {
                abort(400, 'Invalid game_id.');
            }

            return response()->json($this->exporter->exportGame($userId, $gameId));
        }

        return response()->json($this->exporter->exportManifest($userId));
    }
}

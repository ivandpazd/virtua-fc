<?php

namespace App\Http\Actions\Migration;

use App\Models\User;
use App\Modules\Migration\MigrationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Export side, server-to-server endpoint. Marks the user as fully migrated
 * once the import side confirms its job succeeded.
 *
 * After sealing, the export side's "you have moved" page replaces the normal
 * UI for this user. They cannot accidentally play more games on this server.
 */
class SealUser
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('migration_user_id');

        $user = User::findOrFail($userId);
        $user->migration_status = MigrationStatus::COMPLETED;
        $user->migration_completed_at = now();
        $user->save();

        return response()->json([
            'user_id' => $userId,
            'migration_status' => $user->migration_status->value,
            'migration_completed_at' => $user->migration_completed_at?->toAtomString(),
        ]);
    }
}

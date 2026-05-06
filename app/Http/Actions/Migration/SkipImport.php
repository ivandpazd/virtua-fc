<?php

namespace App\Http\Actions\Migration;

use App\Modules\Migration\MigrationStatus;
use App\Modules\Migration\Services\SignedHandoff;
use App\Modules\Migration\TokenPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Import side. POST /migration/skip — user opts to start fresh on this
 * deployment instead of copying their beta data over. We seal the export
 * side so they don't see two parallel accounts, then mark the local user as
 * completed.
 *
 * Allowed from `pending` and `failed`. A user who has already kicked off an
 * import (`in_progress`) cannot skip — the job is mid-flight and skipping
 * underneath it would leave dangling rows. A `completed` user has nothing to
 * skip.
 */
class SkipImport
{
    public function __construct(
        private readonly SignedHandoff $handoff,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        if (! in_array(
            $user->migration_status,
            [MigrationStatus::PENDING, MigrationStatus::FAILED],
            true,
        )) {
            return response()->json([
                'status' => $user->migration_status->value,
                'message' => __('migration.import_skip_not_allowed'),
            ], 409);
        }

        $this->sealPeer($user->id);

        DB::connection('pgsql_control')
            ->table('users')
            ->where('id', $user->id)
            ->update([
                'migration_status' => MigrationStatus::COMPLETED->value,
                'migration_completed_at' => now(),
            ]);

        return response()->json([
            'status' => MigrationStatus::COMPLETED->value,
        ]);
    }

    /**
     * Best-effort seal. We don't fail the skip if the peer is unreachable —
     * the user has explicitly chosen to start fresh, and a stale `pending`
     * row on the export side is recoverable later (an operator can re-seal
     * via direct DB) but a half-skipped local user is more confusing.
     */
    private function sealPeer(int $userId): void
    {
        $peer = (string) config('migration.peer_url', '');
        if ($peer === '') {
            Log::warning('Migration skip: no peer URL configured, not sealing.', ['user_id' => $userId]);

            return;
        }

        $token = $this->handoff->mint(
            TokenPurpose::S2S_SEAL,
            $userId,
            (int) config('migration.s2s_ttl', 300),
        );

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post(rtrim($peer, '/').'/api/migration/seal');

        if (! $response->successful()) {
            Log::warning('Migration skip: peer seal call failed; local user marked completed anyway.', [
                'user_id' => $userId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}

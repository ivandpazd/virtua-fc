<?php

namespace App\Jobs;

use App\Models\User;
use App\Modules\Migration\MigrationStatus;
use App\Modules\Migration\Services\MigrationProgress;
use App\Modules\Migration\Services\SignedHandoff;
use App\Modules\Migration\Services\UserImporter;
use App\Modules\Migration\TokenPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a user's data from the export-side API and imports it locally.
 *
 *   1. GET {peer}/api/migration/export → manifest (control plane + game ids)
 *   2. Hand control plane to UserImporter
 *   3. For each game id: GET {peer}/api/migration/export?game_id=<uuid>
 *      and hand the per-game payload to UserImporter
 *   4. On success: mark local user as completed and call {peer}/api/migration/seal
 *      so the export side locks the user out
 *   5. On failure: mark local user as `failed` and surface the error via
 *      MigrationProgress so the page can show a retry button
 *
 * Each per-game request is a fresh S2S token mint — they're cheap and the
 * token TTL is short, so we don't try to share one token across all games.
 *
 * Idempotency lives at two layers: StartImport flips migration_status from
 * `pending` to `in_progress` atomically before dispatching, so a user-
 * initiated double-click can't kick off two jobs. UserImporter wipes each
 * game's existing rows before re-inserting, so a retry after a partial
 * failure does not collide on existing rows.
 */
class MigrationImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800; // 30 minutes — generous for power users with many seasons
    public int $tries = 1; // Don't auto-retry; a failed import needs human attention

    public function __construct(
        public readonly int $userId,
    ) {
        $this->onQueue('cleanup');
    }

    public function handle(SignedHandoff $handoff, UserImporter $importer): void
    {
        try {
            MigrationProgress::set($this->userId, 1, 'starting');

            $manifest = $this->fetchManifest($handoff);

            MigrationProgress::set($this->userId, 5, 'control_plane');
            $importer->importControlPlane($this->userId, $manifest['control_plane'] ?? []);

            $gameIds = $manifest['game_ids'] ?? [];
            $total = count($gameIds);

            foreach ($gameIds as $i => $gameId) {
                $current = $i + 1;
                // Reserve 10–95% for game imports; 0–10% was control plane,
                // 95–100% is the finalising step at the end.
                $percent = $total === 0
                    ? 95
                    : 10 + (int) round((($current - 1) / $total) * 85);
                MigrationProgress::set($this->userId, $percent, 'games', [
                    'current' => $current,
                    'total' => $total,
                ]);

                $game = $this->fetchGame($handoff, $gameId);
                $importer->importGame($game);
            }

            MigrationProgress::set($this->userId, 95, 'finalizing');
            $this->seal($handoff);
            $this->markCompletedLocally();

            MigrationProgress::set($this->userId, 100, 'completed');
        } catch (\Throwable $e) {
            $this->safeMarkFailedLocally($e);
            MigrationProgress::set($this->userId, 0, 'failed', [
                'error' => $e->getMessage(),
            ]);
            Log::error('Migration import failed', [
                'user_id' => $this->userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function fetchManifest(SignedHandoff $handoff): array
    {
        $response = $this->exportClient($handoff)->get($this->peerBase().'/api/migration/export');

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Export manifest endpoint returned {$response->status()}: ".$response->body()
            );
        }

        $envelope = $response->json();
        if (! is_array($envelope) || ! isset($envelope['format_version'], $envelope['game_ids'])) {
            throw new \RuntimeException('Export manifest endpoint returned an unexpected payload.');
        }

        return $envelope;
    }

    private function fetchGame(SignedHandoff $handoff, string $gameId): array
    {
        $response = $this->exportClient($handoff)
            ->get($this->peerBase().'/api/migration/export', ['game_id' => $gameId]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Export game endpoint returned {$response->status()} for game {$gameId}: ".$response->body()
            );
        }

        $envelope = $response->json();
        if (! is_array($envelope) || ! isset($envelope['game_id'], $envelope['tables'])) {
            throw new \RuntimeException("Export game endpoint returned an unexpected payload for game {$gameId}.");
        }

        return $envelope;
    }

    private function exportClient(SignedHandoff $handoff): PendingRequest
    {
        $token = $handoff->mint(
            TokenPurpose::S2S_EXPORT,
            $this->userId,
            (int) config('migration.s2s_ttl', 300),
        );

        return Http::withToken($token)
            ->acceptJson()
            ->withHeaders(['Accept-Encoding' => 'gzip'])
            ->timeout(600);
    }

    private function seal(SignedHandoff $handoff): void
    {
        $peer = $this->peerBase();
        $token = $handoff->mint(
            TokenPurpose::S2S_SEAL,
            $this->userId,
            (int) config('migration.s2s_ttl', 300),
        );

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post("{$peer}/api/migration/seal");

        if (! $response->successful()) {
            // Don't fail the import for this — the local data is already in
            // place. Log so an operator can re-seal manually if needed.
            Log::warning('Migration seal call failed; local import is complete but the peer was not sealed.', [
                'user_id' => $this->userId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    private function markCompletedLocally(): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }
        $user->migration_status = MigrationStatus::COMPLETED;
        $user->migration_completed_at = now();
        $user->save();
    }

    /**
     * Marking a user as failed is itself a database write — if the original
     * exception was caused by Postgres being down, this write will throw too
     * and would otherwise replace the more diagnostic original. Swallow the
     * secondary error so the caller still sees the real reason.
     */
    private function safeMarkFailedLocally(\Throwable $original): void
    {
        try {
            $user = User::find($this->userId);
            if ($user === null) {
                return;
            }
            $user->migration_status = MigrationStatus::FAILED;
            $user->save();
        } catch (\Throwable $e) {
            Log::error('Failed to mark user as migration-failed during error handling.', [
                'user_id' => $this->userId,
                'original_exception' => $original->getMessage(),
                'secondary_exception' => $e->getMessage(),
            ]);
        }
    }

    private function peerBase(): string
    {
        $peer = (string) config('migration.peer_url', '');
        if ($peer === '') {
            throw new \LogicException('MIGRATION_PEER_URL is not set.');
        }

        return rtrim($peer, '/');
    }
}

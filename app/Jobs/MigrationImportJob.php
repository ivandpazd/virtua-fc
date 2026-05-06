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
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a user's data from the export-side API and imports it locally.
 *
 *   1. Mint S2S_EXPORT bearer → GET {peer}/api/migration/export
 *   2. Hand the JSON envelope to UserImporter
 *   3. On success: mark local user as completed and call {peer}/api/migration/seal
 *      so the export side locks the user out
 *   4. On failure: mark local user as `failed` and surface the error via
 *      MigrationProgress so the page can show a retry button
 *
 * Idempotency lives on the action that dispatches us, not here: the action
 * flips migration_status from `pending` to `in_progress` atomically before
 * dispatching, so a user-initiated double-click can't kick off two jobs.
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
        $this->onQueue('migration');
    }

    public function handle(SignedHandoff $handoff, UserImporter $importer): void
    {
        try {
            MigrationProgress::set($this->userId, 1, 'starting');

            $envelope = $this->fetchEnvelope($handoff);
            $importer->import($this->userId, $envelope);

            $this->seal($handoff);
            $this->markCompletedLocally();

            MigrationProgress::set($this->userId, 100, 'completed');
        } catch (\Throwable $e) {
            $this->markFailedLocally();
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

    private function fetchEnvelope(SignedHandoff $handoff): array
    {
        $peer = $this->peerBase();
        $token = $handoff->mint(
            TokenPurpose::S2S_EXPORT,
            $this->userId,
            (int) config('migration.s2s_ttl', 300),
        );

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(600)
            ->get("{$peer}/api/migration/export");

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Export endpoint returned {$response->status()}: ".$response->body()
            );
        }

        $envelope = $response->json();
        if (! is_array($envelope) || ! isset($envelope['format_version'])) {
            throw new \RuntimeException('Export endpoint returned an unexpected payload.');
        }

        return $envelope;
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

    private function markFailedLocally(): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }
        $user->migration_status = MigrationStatus::FAILED;
        $user->save();
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

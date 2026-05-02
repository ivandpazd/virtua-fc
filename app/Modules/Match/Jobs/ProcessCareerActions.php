<?php

namespace App\Modules\Match\Jobs;

use App\Models\Game;
use App\Modules\Match\Services\CareerActionProcessor;
use App\Support\QueryProfiler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCareerActions implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 180;

    public function __construct(
        public string $gameId,
        public int $ticks,
    ) {
        $this->onQueue('gameplay');
    }

    public function uniqueId(): string
    {
        return $this->gameId;
    }

    public function handle(CareerActionProcessor $processor): void
    {
        // Each tick runs inside its own transaction holding the game row lock.
        // This serializes career actions against matchday advancement and
        // FinalizeMatch — all of which write to the same game_player_match_state
        // / game_players rows and would otherwise deadlock at the PK/FK index
        // level.
        //
        // Per-tick (rather than whole-job) locking keeps the critical section
        // short so a user advancing to the next matchday is only ever blocked
        // by a single tick's work, not the full accumulated batch.
        $jobStart = microtime(true);
        $tickStats = [];

        for ($i = 0; $i < $this->ticks; $i++) {
            $tick = QueryProfiler::start();

            $processed = DB::transaction(function () use ($processor) {
                $game = Game::where('id', $this->gameId)->lockForUpdate()->first();

                if (! $game || ! $game->isProcessingCareerActions()) {
                    return false;
                }

                $processor->process($game);

                return true;
            });

            if (QueryProfiler::enabled()) {
                $stats = $tick->snapshot();
                $tickStats[] = $stats;
                Log::info("[CareerActions {$this->gameId}] tick ".($i + 1)."/{$this->ticks}", $stats);
            }

            if (! $processed) {
                break;
            }
        }

        Game::where('id', $this->gameId)->update(['career_actions_processing_at' => null]);

        if (QueryProfiler::enabled()) {
            Log::info("[CareerActions {$this->gameId}] job summary", [
                'wall_ms' => (int) round((microtime(true) - $jobStart) * 1000),
                'db_ms' => array_sum(array_column($tickStats, 'db_ms')),
                'queries' => array_sum(array_column($tickStats, 'queries')),
                'ticks_run' => count($tickStats),
                'ticks_requested' => $this->ticks,
                'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Game::where('id', $this->gameId)->update(['career_actions_processing_at' => null]);

        Log::error('Career actions processing failed', [
            'game_id' => $this->gameId,
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }
}

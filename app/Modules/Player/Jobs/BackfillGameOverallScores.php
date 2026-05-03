<?php

namespace App\Modules\Player\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Backfill `overall_score` for one game's `game_players` rows by computing
 * ROUND((game_technical_ability + game_physical_ability) / 2.0) from the
 * still-present legacy columns.
 *
 * Why per-game: rows for a single game are heap-clustered (inserted together
 * during game creation), so each UPDATE touches a small, contiguous slice of
 * the table. On Neon's network-storage model that means a handful of
 * page-fetch round trips per game instead of the random scatter the
 * UUID-PK-batched migration produced.
 *
 * Idempotent: the WHERE filter only touches rows where `overall_score IS
 * NULL` and the legacy columns are populated. Re-dispatch is a no-op once a
 * game is fully backfilled.
 */
class BackfillGameOverallScores implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public string $gameId) {}

    public function handle(): int
    {
        return DB::update(
            'UPDATE game_players
             SET overall_score = ROUND((game_technical_ability + game_physical_ability) / 2.0)
             WHERE game_id = ?
               AND overall_score IS NULL
               AND game_technical_ability IS NOT NULL
               AND game_physical_ability IS NOT NULL',
            [$this->gameId]
        );
    }
}

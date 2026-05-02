<?php

namespace App\Modules\Season\Processors;

use App\Modules\Season\Contracts\SeasonProcessor;
use App\Modules\Season\DTOs\SeasonTransitionData;
use App\Modules\Season\Services\SeasonInitializationService;
use App\Models\CupTie;
use App\Models\Game;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Log;

/**
 * Cleans up old matches/cup ties and generates league fixtures for the new season.
 *
 * current_date is finalized later by ContinentalAndCupInitProcessor (priority 106)
 * after all competitions (league, Swiss, cups) have their fixtures.
 *
 * Priority: 30 (runs after promotion/relegation at 26)
 */
class LeagueFixtureProcessor implements SeasonProcessor
{
    public function __construct(
        private readonly SeasonInitializationService $service,
    ) {}

    public function priority(): int
    {
        return 30;
    }

    public function process(Game $game, SeasonTransitionData $data): SeasonTransitionData
    {
        $t0 = microtime(true);
        $deletedMatches = GameMatch::where('game_id', $game->id)->delete();
        $t1 = microtime(true);

        $deletedCupTies = CupTie::where('game_id', $game->id)->delete();
        $t2 = microtime(true);

        $this->service->generateLeagueFixtures($game->id, $data->competitionId, $data->newSeason);
        $t3 = microtime(true);

        Log::info(sprintf(
            '[LeagueFixtureProcessor] game=%s del-matches=%dms (%d rows) del-cupties=%dms (%d rows) generate=%dms total=%dms',
            $game->id,
            ($t1 - $t0) * 1000,
            $deletedMatches,
            ($t2 - $t1) * 1000,
            $deletedCupTies,
            ($t3 - $t2) * 1000,
            ($t3 - $t0) * 1000,
        ));

        return $data;
    }
}

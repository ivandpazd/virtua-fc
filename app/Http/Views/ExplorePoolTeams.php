<?php

namespace App\Http\Views;

use App\Models\Game;
use App\Modules\Competition\Services\CountryConfig;
use App\Modules\Transfer\Services\ExploreService;
use Illuminate\Http\Request;

class ExplorePoolTeams
{
    public function __construct(
        private readonly ExploreService $exploreService,
        private readonly CountryConfig $countryConfig,
    ) {}

    public function __invoke(Request $request, string $gameId, string $poolId)
    {
        $game = Game::findOrFail($gameId);
        abort_if($game->isTournamentMode(), 404);

        // Restrict to pool IDs declared as transfer pools — prevents
        // arbitrary competition_id lookups via this endpoint.
        $allowed = collect($this->countryConfig->allCountryCodes())
            ->flatMap(fn (string $code) => $this->countryConfig->transferPoolIds($code))
            ->unique()
            ->all();

        abort_unless(in_array($poolId, $allowed, true), 404);

        return response()->json(
            $this->exploreService->getTeamPoolGroupedByCountry($gameId, $poolId)
        );
    }
}

<?php

namespace App\Http\Views;

use App\Models\Game;
use App\Modules\Transfer\Services\ExploreService;
use App\Modules\Transfer\Services\TransferHeaderService;
use App\Modules\Transfer\Services\TransferMarketService;
use Illuminate\Http\Request;

class ShowTransferMarket
{
    public function __construct(
        private readonly TransferMarketService $transferMarketService,
        private readonly TransferHeaderService $headerService,
        private readonly ExploreService $exploreService,
    ) {}

    public function __invoke(Request $request, string $gameId)
    {
        $game = Game::findOrFail($gameId);

        $isTransferWindow = $game->isTransferWindowOpen();
        $listings = $this->transferMarketService->getMarketListings($game);
        $freeAgents = $this->exploreService->getFreeAgents($game);

        // Merge AI-listed players and free agents into a single collection
        // sorted by overall rating, so the default view blends both sources
        // instead of grouping listings above free agents.
        $rows = $listings
            ->map(fn ($listing) => [
                'gamePlayer' => $listing->gamePlayer,
                'askingPrice' => $listing->asking_price,
            ])
            ->concat($freeAgents->map(fn ($gp) => [
                'gamePlayer' => $gp,
                'askingPrice' => null,
            ]))
            ->sortByDesc(fn ($row) => $row['gamePlayer']->effective_rating)
            ->values();

        $headerData = $this->headerService->getHeaderData($game);

        $availableBudget = $game->currentInvestment?->transfer_budget ?? 0;

        return view('transfer-market', [
            'game' => $game,
            'rows' => $rows,
            'isTransferWindow' => $isTransferWindow,
            'availableBudget' => $availableBudget,
        ] + $headerData);
    }
}

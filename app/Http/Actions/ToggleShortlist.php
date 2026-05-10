<?php

namespace App\Http\Actions;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\ShortlistedPlayer;
use App\Models\TransferOffer;
use App\Modules\Transfer\Services\ScoutingService;
use App\Support\Money;
use App\Support\PositionMapper;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

class ToggleShortlist
{
    public function __construct(
        private readonly ScoutingService $scoutingService,
    ) {}

    public function __invoke(Request $request, string $gameId, string $playerId)
    {
        $game = Game::findOrFail($gameId);
        $gamePlayer = GamePlayer::where('game_id', $gameId)->with('team')->findOrFail($playerId);

        $existing = ShortlistedPlayer::where('game_id', $gameId)
            ->where('game_player_id', $playerId)
            ->first();

        // Removal is always allowed — even if the player has since become
        // user-owned (e.g. shortlisted before signing), we want the user to be
        // able to clear them out. Only block *adding* an own-club player.
        if (!$existing && $gamePlayer->isUserOwned($game)) {
            $message = __('transfers.cannot_target_own_player');

            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()->back()->with('error', $message);
        }

        // Block shortlisting players the user already has an active pre-contract
        // with (pending or agreed) — the UI hides the action, this guards
        // against direct POSTs / replays.
        if (!$existing) {
            $preContractStatus = TransferOffer::getUserPreContractStatuses(
                $gameId, $game->team_id, [$playerId]
            )[$playerId] ?? null;

            if ($preContractStatus !== null) {
                $message = __('transfers.shortlist_disabled_pre_contract');

                if ($request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 422);
                }

                return redirect()->back()->with('error', $message);
            }
        }

        if ($existing) {
            $existing->delete();
            $message = __('messages.shortlist_removed', ['player' => $gamePlayer->name]);
            $action = 'removed';
        } elseif ($this->scoutingService->isShortlistFull($game)) {
            $message = __('messages.shortlist_full', ['max' => ScoutingService::MAX_SHORTLIST_SIZE]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()->back()->with('error', $message);
        } else {
            $intelLevel = $request->input('source') === 'scout_report'
                ? ShortlistedPlayer::INTEL_REPORT
                : ShortlistedPlayer::INTEL_SURFACE;

            try {
                $entry = ShortlistedPlayer::create([
                    'game_id' => $gameId,
                    'game_player_id' => $playerId,
                    'added_at' => $game->current_date,
                    'intel_level' => $intelLevel,
                ]);

                // Auto-track if slots available
                $this->scoutingService->startTracking($entry, $game);
            } catch (UniqueConstraintViolationException $e) {
                // Concurrent toggle (e.g. double-click) — another request already
                // created the row. Treat as success and reuse the winning row.
                $entry = ShortlistedPlayer::where('game_id', $gameId)
                    ->where('game_player_id', $playerId)
                    ->firstOrFail();
            }

            $message = __('messages.shortlist_added', ['player' => $gamePlayer->name]);
            $action = 'added';
        }

        if ($request->ajax()) {
            $data = ['success' => true, 'message' => $message, 'action' => $action, 'playerId' => $playerId];

            if ($action === 'added') {
                $entry->refresh();
                $gamePlayer->load(['team']);
                $positionDisplay = PositionMapper::getPositionDisplay($gamePlayer->position);

                $data['player'] = [
                    'id' => $gamePlayer->id,
                    'name' => $gamePlayer->name,
                    'position' => $gamePlayer->position,
                    'positionAbbr' => $positionDisplay['abbreviation'],
                    'positionBg' => $positionDisplay['bg'],
                    'positionText' => $positionDisplay['text'],
                    'age' => $gamePlayer->age($game->current_date),
                    'teamName' => $gamePlayer->team?->name,
                    'teamImage' => $gamePlayer->team?->image,
                    'isExpiring' => $gamePlayer->contract_until && $gamePlayer->contract_until <= $game->getSeasonEndDate(),
                    'contractYear' => $gamePlayer->contract_until?->format('Y'),
                    'marketValue' => $gamePlayer->market_value_cents,
                    'formattedMarketValue' => Money::format($gamePlayer->market_value_cents),
                    'intelLevel' => $entry->intel_level ?? ShortlistedPlayer::INTEL_SURFACE,
                    'isTracking' => (bool) $entry->is_tracking,
                    'matchdaysTracked' => $entry->matchdays_tracked ?? 0,
                    'hasExistingOffer' => false,
                    'overallRange' => null,
                    'formattedAskingPrice' => null,
                    'askingPrice' => null,
                    'canAffordFee' => false,
                    'canAffordLoan' => false,
                    'wageDemand' => null,
                    'formattedWageDemand' => null,
                    'bidEuros' => 0,
                    'wageEuros' => 0,
                    'willingness' => null,
                    'willingnessLabel' => null,
                    'rivalInterest' => false,
                ];

                if ($entry->hasReportLevel()) {
                    $detail = $this->scoutingService->getPlayerScoutingDetail($gamePlayer, $game);
                    $data['player']['overallRange'] = $detail['overall_range'];
                    $data['player']['formattedAskingPrice'] = $detail['formatted_asking_price'];
                    $data['player']['askingPrice'] = $detail['asking_price'];
                    $data['player']['canAffordFee'] = $detail['can_afford_fee'];
                    $data['player']['canAffordLoan'] = $detail['can_afford_loan'];
                    $data['player']['wageDemand'] = $detail['wage_demand'];
                    $data['player']['formattedWageDemand'] = $detail['formatted_wage_demand'];
                    $data['player']['bidEuros'] = (int) ($detail['asking_price'] / 100);
                    $data['player']['wageEuros'] = (int) ($detail['wage_demand'] / 100);
                }
            }

            $data['trackingCapacity'] = $this->scoutingService->getTrackingCapacity($game);

            return response()->json($data);
        }

        return redirect()->back()->with('success', $message);
    }
}

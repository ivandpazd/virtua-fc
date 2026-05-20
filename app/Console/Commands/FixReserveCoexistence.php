<?php

namespace App\Console\Commands;

use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\GameStanding;
use App\Models\Team;
use App\Modules\Competition\Services\CountryConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recovery command for games whose reserve-team invariant has drifted:
 * a reserve club (Team::parent_team_id NOT NULL) sharing a competition
 * with its parent. The upstream root cause — the cross-rule parent-
 * relegation/reserve-promotion collision in PromotionRelegationProcessor —
 * is fixed going forward, but games that accumulated the violation over
 * many seasons need a one-off correction.
 *
 * Two repair strategies are attempted per violation, in order:
 *
 *   1. Move the reserve DOWN. Find the next-most-eligible non-reserve
 *      team in a lower tier that doesn't include the reserve's parent
 *      and swap places. Preferred because it leaves the parent's tier
 *      placement (and the league strength of the upper divisions)
 *      untouched.
 *
 *   2. Move the parent UP. Used as a fallback when the reserve has no
 *      tier to move down into (e.g. the violation sits at ESP3A, the
 *      deepest playable tier in Spain). The parent is swapped with the
 *      worst non-reserve in the closest tier above its current one,
 *      restoring the invariant "parent strictly above reserve" by
 *      pushing the parent upward instead of the reserve downward.
 *
 * Both strategies preserve division sizes (direct swap) and run inside
 * the same per-game transaction so a partial failure rolls back cleanly.
 *
 * Side-effects per affected game:
 *   - Clear the country's supercup entries so SupercupQualificationProcessor
 *     will re-derive the field with the corrected top-tier roster on the
 *     next pipeline run.
 *   - Leave `season_transition_step` alone. A previous version cleared it
 *     and that caused the closing pipeline to re-run for the already-
 *     advanced new season, crashing SupercupQualificationProcessor with
 *     "expected 4 qualifiers, got 0".
 *
 * Safe to re-run: each step is idempotent. Default mode is dry-run; pass
 * --fix to apply.
 */
class FixReserveCoexistence extends Command
{
    protected $signature = 'app:fix-reserve-coexistence {--game=} {--fix}';

    protected $description = 'Detect and (optionally) repair reserve teams sharing a competition with their parent club.';

    public function handle(CountryConfig $countryConfig): int
    {
        $apply = (bool) $this->option('fix');
        $specificGame = $this->option('game');

        $games = Game::query()
            ->when($specificGame, fn ($q) => $q->where('id', $specificGame))
            ->get(['id', 'country', 'season', 'season_transition_step']);

        if ($games->isEmpty()) {
            $this->info('No games found.');

            return self::SUCCESS;
        }

        $totalViolations = 0;
        $totalRepairs = 0;

        foreach ($games as $game) {
            $country = $game->country ?? 'ES';
            $reserveTeams = Team::where('country', $country)
                ->whereNotNull('parent_team_id')
                ->get(['id', 'name', 'parent_team_id']);

            if ($reserveTeams->isEmpty()) {
                continue;
            }

            $violations = $this->findViolations($game->id, $reserveTeams, $country, $countryConfig);

            if ($violations->isEmpty()) {
                continue;
            }

            $this->line("Game {$game->id} (season {$game->season}, country {$country}): " . $violations->count() . ' violation(s)');
            $totalViolations += $violations->count();

            if (!$apply) {
                foreach ($violations as $v) {
                    $detail = $v['type'] === 'coexistence'
                        ? "reserve {$v['reserve_name']} ({$v['reserve_id']}) and parent {$v['parent_id']} share {$v['competition_id']}"
                        : "reserve {$v['reserve_name']} ({$v['reserve_id']}) in {$v['competition_id']} is one tier below parent {$v['parent_id']} (fragile — coexistence on parent relegation)";
                    $this->line("  - [{$v['type']}] {$detail}");
                }
                continue;
            }

            $repaired = $this->repairGame($game, $country, $violations, $countryConfig);
            $totalRepairs += $repaired;

            $this->info("  → repaired {$repaired} violation(s) and reset pipeline state");
        }

        $this->line('');
        $this->info("Scanned {$games->count()} game(s) — {$totalViolations} violation(s) detected" . ($apply ? ", {$totalRepairs} repaired." : '. Re-run with --fix to apply.'));

        return self::SUCCESS;
    }

    /**
     * Two violation types are reported:
     *   - 'coexistence': reserve and parent in the same competition (the
     *     bug this command was built for).
     *   - 'fragile': reserve is in a competition immediately below the
     *     parent's. The parent relegating creates coexistence in the next
     *     season transition — exactly the trap the first pass of this
     *     command fell into when it placed a reserve in ESP2 with the
     *     parent in ESP1. Detect and move pre-emptively.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     type: 'coexistence'|'fragile',
     *     reserve_id: string,
     *     reserve_name: string,
     *     parent_id: string,
     *     competition_id: string,
     * }>
     */
    private function findViolations(string $gameId, \Illuminate\Support\Collection $reserveTeams, string $country, CountryConfig $countryConfig)
    {
        $reservesById = $reserveTeams->keyBy('id');

        $reserveEntries = CompetitionEntry::where('game_id', $gameId)
            ->whereIn('team_id', $reservesById->keys())
            ->get(['competition_id', 'team_id']);

        if ($reserveEntries->isEmpty()) {
            return collect();
        }

        $parentIds = $reservesById->pluck('parent_team_id')->unique()->all();
        $parentEntries = CompetitionEntry::where('game_id', $gameId)
            ->whereIn('team_id', $parentIds)
            ->get(['competition_id', 'team_id'])
            ->groupBy('team_id')
            ->map(fn ($e) => $e->pluck('competition_id')->all());

        $tierMap = $countryConfig->tiers($country);

        return $reserveEntries->map(function ($entry) use ($reservesById, $parentEntries, $tierMap, $countryConfig, $country) {
            $reserve = $reservesById->get($entry->team_id);
            $parentDivisions = $parentEntries->get($reserve->parent_team_id, []);

            if (in_array($entry->competition_id, $parentDivisions, true)) {
                return [
                    'type' => 'coexistence',
                    'reserve_id' => $entry->team_id,
                    'reserve_name' => $reserve->name,
                    'parent_id' => $reserve->parent_team_id,
                    'competition_id' => $entry->competition_id,
                ];
            }

            // Fragile: reserve sits immediately below the parent. The next
            // parent relegation creates coexistence. Detected by comparing
            // tiers (lower tier number = higher division).
            $reserveTier = $this->resolveTier($entry->competition_id, $tierMap, $countryConfig, $country);
            foreach ($parentDivisions as $parentCompetitionId) {
                $parentTier = $this->resolveTier($parentCompetitionId, $tierMap, $countryConfig, $country);
                if ($parentTier !== null && $reserveTier !== null && $reserveTier === $parentTier + 1) {
                    return [
                        'type' => 'fragile',
                        'reserve_id' => $entry->team_id,
                        'reserve_name' => $reserve->name,
                        'parent_id' => $reserve->parent_team_id,
                        'competition_id' => $entry->competition_id,
                    ];
                }
            }

            return null;
        })->filter()->values();
    }

    /**
     * Apply repairs inside a transaction so a partial failure rolls back
     * cleanly. Returns the number of successful swaps.
     *
     * @param  \Illuminate\Support\Collection<int, array{reserve_id: string, reserve_name: string, parent_id: string, competition_id: string}>  $violations
     */
    private function repairGame(Game $game, string $country, $violations, CountryConfig $countryConfig): int
    {
        return DB::transaction(function () use ($game, $country, $violations, $countryConfig) {
            $repaired = 0;

            foreach ($violations as $v) {
                // Strategy 1: move the reserve DOWN.
                $replacement = $this->findReplacement($game->id, $country, $v, $countryConfig);

                if ($replacement !== null) {
                    $this->swapTeams(
                        $game->id,
                        teamAId: $v['reserve_id'],
                        teamBId: $replacement['team_id'],
                        teamACompetition: $v['competition_id'],
                        teamBCompetition: $replacement['competition_id'],
                    );

                    Log::info('[ReserveCoexistenceFix] Swapped (reserve-down)', [
                        'game_id' => $game->id,
                        'violation' => $v,
                        'replacement' => $replacement,
                    ]);

                    $repaired++;
                    continue;
                }

                // Strategy 2: move the parent UP.
                $promotion = $this->findParentPromotion($game->id, $country, $v, $countryConfig);

                if ($promotion !== null) {
                    $this->swapTeams(
                        $game->id,
                        teamAId: $v['parent_id'],
                        teamBId: $promotion['team_id'],
                        teamACompetition: $promotion['parent_competition_id'],
                        teamBCompetition: $promotion['competition_id'],
                    );

                    Log::info('[ReserveCoexistenceFix] Swapped (parent-up)', [
                        'game_id' => $game->id,
                        'violation' => $v,
                        'promotion' => $promotion,
                    ]);

                    $repaired++;
                    continue;
                }

                Log::warning('[ReserveCoexistenceFix] No replacement found — skipping', [
                    'game_id' => $game->id,
                    'violation' => $v,
                ]);
                $this->warn("    no replacement found for {$v['reserve_name']} — skipped");
            }

            if ($repaired > 0) {
                // Clear ESPSUP-style supercup entries so the bump step in
                // SeasonInitializationService::updateCupEntryRoundsForSupercupTeams
                // becomes a no-op (no supercup teams to bump = no odd
                // round-1 pool). The supercup for THIS season simply doesn't
                // get played; next season's closing pipeline re-derives it
                // cleanly. Use the country's supercup config rather than
                // hard-coding ESPSUP.
                $supercupConfig = $countryConfig->supercup($country);
                if ($supercupConfig !== null && !empty($supercupConfig['competition'])) {
                    CompetitionEntry::where('game_id', $game->id)
                        ->where('competition_id', $supercupConfig['competition'])
                        ->delete();
                }

                // DON'T clear season_transition_step. A first version did,
                // which caused the closing pipeline to re-run for the
                // already-advanced new season — which has no played data,
                // and SupercupQualificationProcessor then crashed with
                // "expected 4 qualifiers, got 0". Leaving the checkpoint
                // in place lets the pipeline resume from where it crashed
                // in the setup phase, where the cup draw can now succeed
                // with the empty supercup field.
            }

            return $repaired;
        });
    }

    /**
     * Find a non-reserve team to swap with the violating reserve. Walks
     * tiers strictly below the violation from DEEPEST to shallowest — a
     * reserve placed at the bottom of the pyramid is safe from any
     * realistic parent relegation, whereas placing it one tier below the
     * parent recreates the same fragile state the next season transition
     * would collapse.
     *
     * Skips any tier where the parent is currently present (would be
     * immediate coexistence) and any tier whose only competitions host
     * reserves (replacing a reserve with another reserve solves nothing).
     *
     * @param  array{reserve_id: string, parent_id: string, competition_id: string}  $violation
     * @return array{team_id: string, competition_id: string}|null
     */
    private function findReplacement(string $gameId, string $country, array $violation, CountryConfig $countryConfig): ?array
    {
        $tierMap = $countryConfig->tiers($country);

        $violationTier = $this->resolveTier($violation['competition_id'], $tierMap, $countryConfig, $country);
        if ($violationTier === null) {
            return null;
        }

        $parentCompetitions = CompetitionEntry::where('game_id', $gameId)
            ->where('team_id', $violation['parent_id'])
            ->pluck('competition_id')
            ->all();

        $tiersBelow = array_values(array_filter(array_keys($tierMap), fn ($t) => $t > $violationTier));
        rsort($tiersBelow); // deepest first — see method docblock

        foreach ($tiersBelow as $tier) {
            foreach ($countryConfig->tierCompetitionIds($country, $tier) as $candidateCompetition) {
                if (in_array($candidateCompetition, $parentCompetitions, true)) {
                    continue;
                }

                $candidate = $this->worstNonReserveIn($gameId, $candidateCompetition);
                if ($candidate !== null) {
                    return [
                        'team_id' => $candidate,
                        'competition_id' => $candidateCompetition,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Fallback strategy: move the PARENT up one tier (or more, if the
     * closest tier above can't absorb the swap). Used when
     * findReplacement() returns null because the violation sits at the
     * deepest tier (e.g. ESP3A in Spain) so the reserve has nowhere to
     * descend to.
     *
     * Walks tiers strictly above the parent's current tier from closest
     * (one tier up) outward. For each candidate competition at that tier,
     * picks the worst non-reserve team and returns it as the swap partner.
     * Skips any candidate competition that already hosts the reserve
     * (defense-in-depth — a team only sits in one league, but this avoids
     * a no-op swap if upstream state is inconsistent).
     *
     * Returned tuple includes `parent_competition_id` (the parent's
     * current competition) so the caller can issue a precise swap without
     * re-querying.
     *
     * @param  array{reserve_id: string, parent_id: string, competition_id: string}  $violation
     * @return array{team_id: string, competition_id: string, parent_competition_id: string}|null
     */
    private function findParentPromotion(string $gameId, string $country, array $violation, CountryConfig $countryConfig): ?array
    {
        $tierMap = $countryConfig->tiers($country);

        $parentCompetitions = CompetitionEntry::where('game_id', $gameId)
            ->where('team_id', $violation['parent_id'])
            ->pluck('competition_id')
            ->all();

        if (empty($parentCompetitions)) {
            return null;
        }

        // Anchor on the parent's deepest current tier — if the parent
        // is somehow in multiple competitions, promoting from the lowest
        // is the least disruptive choice that still restores the invariant.
        $parentTier = null;
        $parentCompetitionId = null;
        foreach ($parentCompetitions as $comp) {
            $tier = $this->resolveTier($comp, $tierMap, $countryConfig, $country);
            if ($tier !== null && ($parentTier === null || $tier > $parentTier)) {
                $parentTier = $tier;
                $parentCompetitionId = $comp;
            }
        }

        if ($parentTier === null || $parentCompetitionId === null) {
            return null;
        }

        // Tier numbering: lower tier number = higher division. "One tier
        // above" the parent means $parentTier - 1. Walk closest-first by
        // taking tier numbers strictly less than parent's and rsort'ing
        // (so the highest tier_number below parent's — i.e. the closest
        // division above — is tried first).
        $tiersAbove = array_values(array_filter(array_keys($tierMap), fn ($t) => $t < $parentTier));
        rsort($tiersAbove);

        foreach ($tiersAbove as $tier) {
            foreach ($countryConfig->tierCompetitionIds($country, $tier) as $candidateCompetition) {
                $hostingReserve = CompetitionEntry::where('game_id', $gameId)
                    ->where('competition_id', $candidateCompetition)
                    ->where('team_id', $violation['reserve_id'])
                    ->exists();
                if ($hostingReserve) {
                    continue;
                }

                $candidate = $this->worstNonReserveIn($gameId, $candidateCompetition);
                if ($candidate !== null) {
                    return [
                        'team_id' => $candidate,
                        'competition_id' => $candidateCompetition,
                        'parent_competition_id' => $parentCompetitionId,
                    ];
                }
            }
        }

        return null;
    }

    private function resolveTier(string $competitionId, array $tierMap, CountryConfig $countryConfig, string $country): ?int
    {
        foreach (array_keys($tierMap) as $tier) {
            foreach ($countryConfig->tierCompetitionIds($country, $tier) as $id) {
                if ($id === $competitionId) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * Worst-ranked non-reserve team in a competition (last position in
     * standings, or last entry if standings are empty / placeholder).
     */
    private function worstNonReserveIn(string $gameId, string $competitionId): ?string
    {
        $candidates = CompetitionEntry::where('game_id', $gameId)
            ->where('competition_id', $competitionId)
            ->join('teams', 'teams.id', '=', 'competition_entries.team_id')
            ->whereNull('teams.parent_team_id')
            ->pluck('competition_entries.team_id')
            ->all();

        if (empty($candidates)) {
            return null;
        }

        $worst = GameStanding::where('game_id', $gameId)
            ->where('competition_id', $competitionId)
            ->whereIn('team_id', $candidates)
            ->where('played', '>', 0)
            ->orderByDesc('position')
            ->value('team_id');

        return $worst ?? end($candidates) ?: null;
    }

    /**
     * Swap two teams between two competitions: each moves into the other's
     * competition. Mirrors the move semantics in
     * PromotionRelegationProcessor::moveTeam but as a direct swap. The
     * helper is symmetric — the A/B labels are positional, not semantic,
     * so the same method serves both repair strategies (reserve-down and
     * parent-up).
     */
    private function swapTeams(
        string $gameId,
        string $teamAId,
        string $teamBId,
        string $teamACompetition,
        string $teamBCompetition,
    ): void {
        $this->moveTeam($gameId, $teamAId, $teamACompetition, $teamBCompetition);
        $this->moveTeam($gameId, $teamBId, $teamBCompetition, $teamACompetition);
    }

    private function moveTeam(string $gameId, string $teamId, string $fromDivision, string $toDivision): void
    {
        CompetitionEntry::where('game_id', $gameId)
            ->where('competition_id', $fromDivision)
            ->where('team_id', $teamId)
            ->delete();

        CompetitionEntry::updateOrCreate(
            [
                'game_id' => $gameId,
                'competition_id' => $toDivision,
                'team_id' => $teamId,
            ],
            ['entry_round' => 1],
        );

        GameStanding::where('game_id', $gameId)
            ->where('competition_id', $fromDivision)
            ->where('team_id', $teamId)
            ->delete();
    }
}

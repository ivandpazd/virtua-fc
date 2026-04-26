<?php

namespace App\Console\Commands;

use App\Models\CompetitionEntry;
use App\Models\CupTie;
use App\Models\Game;
use App\Models\GameStanding;
use App\Models\SimulatedSeason;
use App\Models\Team;
use App\Modules\Competition\Contracts\PlayoffGenerator;
use App\Modules\Competition\Enums\PlayoffState;
use App\Modules\Competition\Playoffs\PlayoffGeneratorFactory;
use App\Modules\Competition\Services\CountryConfig;
use App\Modules\Competition\Services\ReserveTeamFilter;
use App\Modules\Season\Jobs\ProcessSeasonTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Forward-repair a stuck season transition by promoting missing teams to balance
 * affected divisions, then re-dispatch the pipeline. Temporary recovery tool —
 * doesn't address the underlying cause, just fixes the data so the user can
 * keep playing.
 *
 * Imbalances are detected by comparing each tier's expected team count
 * (from countries.php) against the rows in competition_entries. Each "short"
 * top division is paired with the "long" bottom division it relegated into;
 * replacements are picked playoff-runner-up first, then by next eligible
 * standings/simulated position (skipping reserve teams whose parent is in the
 * top division and any team already promoted in this transition).
 */
class UnstickSeasonTransition extends Command
{
    protected $signature = 'app:unstick-season-transition {gameId} {--dry-run}';

    protected $description = 'Repair a stuck season transition by balancing promotion/relegation';

    public function handle(
        CountryConfig $countryConfig,
        PlayoffGeneratorFactory $playoffFactory,
        ReserveTeamFilter $reserveFilter,
    ): int {
        $game = Game::find($this->argument('gameId'));

        if (!$game) {
            $this->error('Game not found.');
            return self::FAILURE;
        }

        if (!$game->season_transition_data) {
            $this->error('Game is not in a stuck transition state (season_transition_data is null).');
            return self::FAILURE;
        }

        if (!$game->isTransitioningSeason()) {
            $this->error('Game has season_transition_data but season_transitioning_at is null — re-dispatched job would no-op. Set season_transitioning_at first.');
            return self::FAILURE;
        }

        $imbalances = $this->detectImbalances($game, $countryConfig);

        if (empty($imbalances)) {
            $this->info('No team-count imbalances detected.');
            return self::SUCCESS;
        }

        $this->info('Detected imbalances:');
        $this->table(
            ['Competition', 'Expected', 'Actual', 'Delta'],
            collect($imbalances)->map(fn ($i) => [
                $i['competition_id'],
                $i['expected'],
                $i['actual'],
                ($i['delta'] > 0 ? '+' : '') . $i['delta'],
            ])->all(),
        );

        $repairs = $this->planRepairs($imbalances, $game, $countryConfig, $playoffFactory, $reserveFilter);

        if ($repairs === null) {
            $this->error('Could not derive a repair plan from these imbalances.');
            return self::FAILURE;
        }

        if (empty($repairs)) {
            $this->warn('Imbalances detected but no repairs needed (deltas may not be correctable by promotion).');
            return self::FAILURE;
        }

        $this->info('Planned moves:');
        $this->table(
            ['From', 'To', 'Team', 'Source'],
            collect($repairs)->map(fn ($r) => [$r['from'], $r['to'], $r['team_name'], $r['source']])->all(),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes applied.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Apply these moves and re-dispatch the transition?', true)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($game, $repairs) {
            $playerTeamId = $game->team_id;
            foreach ($repairs as $r) {
                $this->moveTeam($game->id, $r['team_id'], $r['from'], $r['to'], $playerTeamId);
            }

            $touchedCompetitions = collect($repairs)->pluck('from')
                ->merge(collect($repairs)->pluck('to'))
                ->unique();

            foreach ($touchedCompetitions as $cid) {
                $this->resortPositions($game->id, $cid);
            }
        });

        ProcessSeasonTransition::dispatch($game->id);

        $this->info('Repairs applied and ProcessSeasonTransition re-dispatched.');
        return self::SUCCESS;
    }

    /**
     * @return list<array{competition_id: string, expected: int, actual: int, delta: int}>
     */
    private function detectImbalances(Game $game, CountryConfig $countryConfig): array
    {
        $imbalances = [];

        foreach ($countryConfig->playableCountryCodes() as $countryCode) {
            foreach ($countryConfig->flattenedTiers($countryCode) as $tier) {
                $competitionId = $tier['competition'];
                $expected = $tier['teams'] ?? null;

                if (!$expected) {
                    continue;
                }

                $actual = CompetitionEntry::where('game_id', $game->id)
                    ->where('competition_id', $competitionId)
                    ->count();

                if ($actual !== $expected) {
                    $imbalances[] = [
                        'competition_id' => $competitionId,
                        'expected' => $expected,
                        'actual' => $actual,
                        'delta' => $actual - $expected,
                    ];
                }
            }
        }

        return $imbalances;
    }

    /**
     * Pair "short" top divisions with "long" bottom divisions and pick replacement
     * teams for each missing slot.
     *
     * @param  list<array{competition_id: string, expected: int, actual: int, delta: int}>  $imbalances
     * @return list<array{team_id: string, team_name: string, from: string, to: string, source: string}>|null
     */
    private function planRepairs(
        array $imbalances,
        Game $game,
        CountryConfig $countryConfig,
        PlayoffGeneratorFactory $playoffFactory,
        ReserveTeamFilter $reserveFilter,
    ): ?array {
        $byCompetition = collect($imbalances)->keyBy('competition_id');
        $repairs = [];
        $alreadyChosen = []; // team_ids picked in this run, to avoid double-picking

        foreach ($countryConfig->playableCountryCodes() as $countryCode) {
            foreach ($countryConfig->promotions($countryCode) as $rule) {
                $top = $rule['top_division'];

                // The "bottom" of a rule may span multiple competitions — e.g.
                // ESP2↔ESP3 pulls from ESP3A and ESP3B (Primera RFEF's two
                // groups). playoff_source_divisions enumerates the full set
                // when present; otherwise it's just bottom_division.
                $bottomGroup = array_values(array_unique(array_merge(
                    [$rule['bottom_division']],
                    $rule['playoff_source_divisions'] ?? [],
                )));

                $topImbalance = $byCompetition[$top] ?? null;
                if (!$topImbalance || $topImbalance['delta'] >= 0) {
                    continue;
                }
                $missing = -$topImbalance['delta'];

                // Identify which bottom-group competitions are "long" (delta > 0).
                // Repairs only pull from long competitions — pulling from a
                // balanced one would just create a new imbalance.
                $longBottoms = []; // cid => remaining surplus to consume
                $surplus = 0;
                foreach ($bottomGroup as $bid) {
                    $b = $byCompetition[$bid] ?? null;
                    if ($b && $b['delta'] > 0) {
                        $longBottoms[$bid] = $b['delta'];
                        $surplus += $b['delta'];
                    }
                }

                if ($surplus !== $missing) {
                    $this->warn(sprintf(
                        'Skipping %s↔%s: top short by %d, bottom-group surplus is %d — can\'t pair cleanly.',
                        $top, implode('+', $bottomGroup), $missing, $surplus,
                    ));
                    continue;
                }

                $candidates = $this->findReplacementCandidates(
                    $game,
                    $top,
                    $longBottoms,
                    $missing,
                    $alreadyChosen,
                    $playoffFactory,
                    $reserveFilter,
                );

                if (count($candidates) < $missing) {
                    $this->warn(sprintf(
                        'Could not find %d replacement(s) for %s↔%s; only found %d.',
                        $missing, $top, implode('+', $bottomGroup), count($candidates),
                    ));
                    return null;
                }

                foreach ($candidates as $c) {
                    $repairs[] = [
                        'team_id' => $c['team_id'],
                        'team_name' => $c['team_name'],
                        'from' => $c['from'],
                        'to' => $top,
                        'source' => $c['source'],
                    ];
                    $alreadyChosen[] = $c['team_id'];
                }
            }
        }

        return $repairs;
    }

    /**
     * Find $count replacement teams for a top division, drawing only from
     * long-bottom competitions (each capped at its own surplus). For
     * single-feeder rules tries playoff runner-up first; otherwise walks
     * each long bottom's standings/simulated season for the next eligible
     * team (skipping reserves whose parent is in the top division and any
     * team already in the top division or previously chosen in this run).
     *
     * @param  array<string, int>  $longBottoms  competition_id => remaining surplus
     * @param  list<string>  $alreadyChosen
     * @return list<array{team_id: string, team_name: string, from: string, source: string}>
     */
    private function findReplacementCandidates(
        Game $game,
        string $top,
        array $longBottoms,
        int $count,
        array $alreadyChosen,
        PlayoffGeneratorFactory $playoffFactory,
        ReserveTeamFilter $reserveFilter,
    ): array {
        $picked = [];
        $topTeamIds = CompetitionEntry::where('game_id', $game->id)
            ->where('competition_id', $top)
            ->pluck('team_id')
            ->all();

        $blocklist = array_merge($topTeamIds, $alreadyChosen);

        // 1. Try playoff runner-up. The runner-up's *actual* current competition
        // (looked up from competition_entries) must be one of the long bottoms —
        // otherwise moving them would leave a stale entry in their real
        // division and create a fresh imbalance there.
        $firstBid = array_key_first($longBottoms);
        $generator = $firstBid ? $playoffFactory->forCompetition($firstBid) : null;
        if ($generator && $generator->state($game) === PlayoffState::Completed) {
            $runnerUp = $this->findPlayoffRunnerUp($game->id, $generator->getCompetitionId(), $generator);
            if ($runnerUp && !in_array($runnerUp['team_id'], $blocklist, true)) {
                $actualFrom = CompetitionEntry::where('game_id', $game->id)
                    ->where('team_id', $runnerUp['team_id'])
                    ->whereIn('competition_id', array_keys($longBottoms))
                    ->value('competition_id');

                if ($actualFrom && ($longBottoms[$actualFrom] ?? 0) > 0) {
                    $picked[] = $runnerUp + ['source' => 'playoff_runner_up', 'from' => $actualFrom];
                    $blocklist[] = $runnerUp['team_id'];
                    $longBottoms[$actualFrom]--;
                }
            }
        }

        if (count($picked) >= $count) {
            return array_slice($picked, 0, $count);
        }

        // 2. Walk each long bottom's standings/simulated for next eligible,
        // capping picks per bottom at that bottom's remaining surplus.
        $topTeamIdsCollection = collect($topTeamIds);
        foreach ($longBottoms as $bid => $remaining) {
            if ($remaining <= 0 || count($picked) >= $count) {
                continue;
            }
            $candidates = $this->getBottomDivisionCandidates($game, $bid);
            $parentMap = $reserveFilter->loadParentTeamIds(array_column($candidates, 'team_id'));

            foreach ($candidates as $candidate) {
                if ($remaining <= 0 || count($picked) >= $count) {
                    break;
                }
                if (in_array($candidate['team_id'], $blocklist, true)) {
                    continue;
                }
                if ($reserveFilter->isBlockedReserveTeam($candidate['team_id'], $topTeamIdsCollection, $parentMap)) {
                    continue;
                }
                $picked[] = $candidate + [
                    'source' => "{$bid}_pos_{$candidate['position']}",
                    'from' => $bid,
                ];
                $blocklist[] = $candidate['team_id'];
                $remaining--;
            }
        }

        return $picked;
    }

    /**
     * @return array{team_id: string, team_name: string}|null
     */
    private function findPlayoffRunnerUp(string $gameId, string $bottomDivision, PlayoffGenerator $generator): ?array
    {
        $finalTie = CupTie::where('game_id', $gameId)
            ->where('competition_id', $bottomDivision)
            ->where('round_number', $generator->getTotalRounds())
            ->where('completed', true)
            ->first();

        if (!$finalTie || !$finalTie->winner_id) {
            return null;
        }

        $loserId = $finalTie->home_team_id === $finalTie->winner_id
            ? $finalTie->away_team_id
            : $finalTie->home_team_id;

        $team = Team::find($loserId);
        if (!$team) {
            return null;
        }

        return ['team_id' => $loserId, 'team_name' => $team->name];
    }

    /**
     * Bottom-division teams ordered by finishing position. Prefers real
     * standings; falls back to SimulatedSeason for non-played leagues.
     *
     * @return list<array{team_id: string, team_name: string, position: int}>
     */
    private function getBottomDivisionCandidates(Game $game, string $competitionId): array
    {
        $standings = GameStanding::where('game_id', $game->id)
            ->where('competition_id', $competitionId)
            ->with('team')
            ->orderBy('position')
            ->get();

        if ($standings->isNotEmpty()) {
            return $standings->map(fn ($s) => [
                'team_id' => $s->team_id,
                'team_name' => $s->team->name ?? 'Unknown',
                'position' => $s->position,
            ])->values()->all();
        }

        $simulated = SimulatedSeason::where('game_id', $game->id)
            ->where('season', $game->season)
            ->where('competition_id', $competitionId)
            ->first();

        if (!$simulated) {
            return [];
        }

        $teams = Team::whereIn('id', $simulated->results)->get()->keyBy('id');
        $out = [];
        foreach ($simulated->results as $i => $teamId) {
            if ($teams->has($teamId)) {
                $out[] = [
                    'team_id' => $teamId,
                    'team_name' => $teams[$teamId]->name,
                    'position' => $i + 1,
                ];
            }
        }
        return $out;
    }

    /**
     * Mirrors PromotionRelegationProcessor::moveTeam.
     */
    private function moveTeam(string $gameId, string $teamId, string $from, string $to, string $playerTeamId): void
    {
        CompetitionEntry::where('game_id', $gameId)
            ->where('competition_id', $from)
            ->where('team_id', $teamId)
            ->delete();

        CompetitionEntry::updateOrCreate(
            ['game_id' => $gameId, 'competition_id' => $to, 'team_id' => $teamId],
            ['entry_round' => 1],
        );

        GameStanding::where('game_id', $gameId)
            ->where('competition_id', $from)
            ->where('team_id', $teamId)
            ->delete();

        $targetHasStandings = GameStanding::where('game_id', $gameId)
            ->where('competition_id', $to)
            ->exists();

        if ($targetHasStandings) {
            GameStanding::firstOrCreate([
                'game_id' => $gameId,
                'competition_id' => $to,
                'team_id' => $teamId,
            ], [
                'position' => 99,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
                'points' => 0,
            ]);
        }

        if ($teamId === $playerTeamId) {
            Game::where('id', $gameId)
                ->where('team_id', $teamId)
                ->update(['competition_id' => $to]);
        }
    }

    /**
     * Mirrors PromotionRelegationProcessor::resortPositions.
     */
    private function resortPositions(string $gameId, string $competitionId): void
    {
        $standings = GameStanding::where('game_id', $gameId)
            ->where('competition_id', $competitionId)
            ->orderBy('position')
            ->get();

        if ($standings->isEmpty()) {
            return;
        }

        foreach ($standings->values() as $index => $standing) {
            $newPosition = $index + 1;
            if ($standing->position !== $newPosition) {
                $standing->update(['position' => $newPosition]);
            }
        }
    }

}

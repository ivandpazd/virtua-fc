<?php

namespace App\Modules\Manager\Services;

use App\Models\Game;
use App\Models\ManagerStats;

/**
 * Merges career stats from an external `manager_stats` source (the OLD beta
 * server) into NEW orphan rows — rows where `game_id IS NULL` because the
 * user deleted the career on NEW. The OLD row and the NEW orphan represent
 * the user's *separate* runs at the same team (the migration manifest bug
 * sometimes left collapsed survivors on NEW that the user then re-played and
 * deleted), so the merge is additive for cumulative columns and "max" for
 * peak/streak columns. Win percentage is recomputed from the summed totals.
 *
 * Matching is by (`user_id`, `team_id`, `game_mode`). Several OLD rows or
 * several NEW orphans sharing a key are all legit non-overlapping career
 * runs (uniqueness on `manager_stats` is only on `game_id`, and deleting a
 * career nulls `game_id` rather than removing the row — see migration
 * `2026_04_02_000000_preserve_manager_data_on_game_deletion`). All N rows on
 * each side are folded into a single canonical orphan with the additive/max
 * rules; duplicate orphans are deleted afterwards. OLD-export rows predate
 * the `game_mode` column so they are treated as career mode.
 */
class OrphanManagerStatsMerger
{
    /**
     * @param iterable<array<string,mixed>> $oldRows
     * @return array{
     *     merged: list<array{user_id:int,team_id:string,game_mode:string,old_rows:int,duplicate_orphans:int,before:array<string,int|float>,after:array<string,int|float>}>,
     *     no_old_match: list<array{user_id:int,team_id:string,game_mode:string}>,
     * }
     */
    public function merge(iterable $oldRows, bool $dryRun = false): array
    {
        $oldByKey = $this->indexByUserTeam($oldRows);

        $orphansByKey = [];
        $orphans = ManagerStats::whereNull('game_id')
            ->whereNotNull('team_id')
            ->get();
        foreach ($orphans as $orphan) {
            $mode = $orphan->game_mode ?: Game::MODE_CAREER;
            $orphansByKey[$this->key($orphan->user_id, $orphan->team_id, $mode)][] = $orphan;
        }

        $summary = [
            'merged' => [],
            'no_old_match' => [],
        ];

        foreach ($orphansByKey as $key => $matching) {
            [$userId, $teamId, $gameMode] = $this->splitKey($key);
            $oldMatches = $oldByKey[$key] ?? [];

            // Pick the orphan with the most matches as canonical so the report
            // line shows the meaningful "before". Tie-break by id for stable
            // ordering across runs.
            usort($matching, fn (ManagerStats $a, ManagerStats $b) =>
                ($b->matches_played <=> $a->matches_played)
                ?: strcmp((string) $a->id, (string) $b->id),
            );
            $canonical = array_shift($matching);
            $duplicates = $matching;

            if (count($duplicates) === 0 && count($oldMatches) === 0) {
                $summary['no_old_match'][] = [
                    'user_id' => $userId,
                    'team_id' => $teamId,
                    'game_mode' => $gameMode,
                ];
                continue;
            }

            $before = $this->snapshot($canonical);
            foreach ($duplicates as $duplicate) {
                $this->applyMerge($canonical, $this->modelToRow($duplicate));
            }
            foreach ($oldMatches as $old) {
                $this->applyMerge($canonical, $old);
            }
            $canonical->recalculateWinPercentage();
            $after = $this->snapshot($canonical);

            if (! $dryRun) {
                $canonical->save();
                foreach ($duplicates as $duplicate) {
                    $duplicate->delete();
                }
            }

            $summary['merged'][] = [
                'user_id' => $userId,
                'team_id' => $teamId,
                'game_mode' => $gameMode,
                'old_rows' => count($oldMatches),
                'duplicate_orphans' => count($duplicates),
                'before' => $before,
                'after' => $after,
            ];
        }

        return $summary;
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     * @return array<string, list<array<string,mixed>>>
     */
    private function indexByUserTeam(iterable $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $teamId = $row['team_id'] ?? null;
            if ($userId === null || $teamId === null) {
                continue;
            }
            // OLD-server export predates the `game_mode` column, so all OLD
            // rows are implicitly Career mode.
            $byKey[$this->key((int) $userId, (string) $teamId, Game::MODE_CAREER)][] = $row;
        }

        return $byKey;
    }

    /**
     * @return array<string,int>
     */
    private function modelToRow(ManagerStats $stats): array
    {
        return [
            'matches_played' => (int) $stats->matches_played,
            'matches_won' => (int) $stats->matches_won,
            'matches_drawn' => (int) $stats->matches_drawn,
            'matches_lost' => (int) $stats->matches_lost,
            'seasons_completed' => (int) $stats->seasons_completed,
            'longest_unbeaten_streak' => (int) $stats->longest_unbeaten_streak,
            'current_unbeaten_streak' => (int) $stats->current_unbeaten_streak,
        ];
    }

    private function applyMerge(ManagerStats $orphan, array $old): void
    {
        $orphan->matches_played += (int) ($old['matches_played'] ?? 0);
        $orphan->matches_won += (int) ($old['matches_won'] ?? 0);
        $orphan->matches_drawn += (int) ($old['matches_drawn'] ?? 0);
        $orphan->matches_lost += (int) ($old['matches_lost'] ?? 0);
        $orphan->seasons_completed += (int) ($old['seasons_completed'] ?? 0);

        $orphan->longest_unbeaten_streak = max(
            (int) $orphan->longest_unbeaten_streak,
            (int) ($old['longest_unbeaten_streak'] ?? 0),
        );
        $orphan->current_unbeaten_streak = max(
            (int) $orphan->current_unbeaten_streak,
            (int) ($old['current_unbeaten_streak'] ?? 0),
        );
    }

    /**
     * @return array<string,int|float>
     */
    private function snapshot(ManagerStats $stats): array
    {
        return [
            'matches_played' => (int) $stats->matches_played,
            'matches_won' => (int) $stats->matches_won,
            'matches_drawn' => (int) $stats->matches_drawn,
            'matches_lost' => (int) $stats->matches_lost,
            'win_percentage' => (float) $stats->win_percentage,
            'current_unbeaten_streak' => (int) $stats->current_unbeaten_streak,
            'longest_unbeaten_streak' => (int) $stats->longest_unbeaten_streak,
            'seasons_completed' => (int) $stats->seasons_completed,
        ];
    }

    private function key(int $userId, string $teamId, string $gameMode): string
    {
        return $userId . '|' . $teamId . '|' . $gameMode;
    }

    /**
     * @return array{0:int,1:string,2:string}
     */
    private function splitKey(string $key): array
    {
        [$userId, $teamId, $gameMode] = explode('|', $key, 3);

        return [(int) $userId, $teamId, $gameMode];
    }
}

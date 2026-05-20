<?php

namespace App\Console\Commands;

use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Models\GameStanding;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Print a focused state dump for a game stuck mid season-transition:
 * checkpoint step, reserve/parent coexistence map, and per-competition
 * entry/standings row counts. Read-only — no writes.
 */
class DiagnoseStuckGame extends Command
{
    protected $signature = 'app:diagnose-stuck-game {game}';

    protected $description = 'Print state of a game stuck mid season-transition (read-only).';

    public function handle(): int
    {
        $gameId = $this->argument('game');
        $game = Game::find($gameId);

        if (!$game) {
            $this->error("Game {$gameId} not found.");
            return self::FAILURE;
        }

        $this->line('=== Game ===');
        $this->line("id: {$game->id}");
        $this->line("season: {$game->season}");
        $this->line("country: {$game->country}");
        $this->line("competition_id: {$game->competition_id}");
        $this->line('season_transition_step: ' . ($game->season_transition_step ?? 'NULL'));
        $this->line('season_transitioning_at: ' . ($game->season_transitioning_at ?? 'NULL'));

        $this->line('');
        $this->line("=== Reserve teams ({$game->country}) and their parents ===");
        $reserves = Team::where('country', $game->country)
            ->whereNotNull('parent_team_id')
            ->get(['id', 'name', 'parent_team_id']);

        foreach ($reserves as $r) {
            $rEntry = CompetitionEntry::where('game_id', $gameId)
                ->where('team_id', $r->id)
                ->value('competition_id');
            $pEntry = CompetitionEntry::where('game_id', $gameId)
                ->where('team_id', $r->parent_team_id)
                ->value('competition_id');
            $pName = Team::where('id', $r->parent_team_id)->value('name');
            $flag = ($rEntry !== null && $rEntry === $pEntry) ? '  <-- COEXISTENCE' : '';
            $this->line("  {$r->name} ({$rEntry}) <- parent {$pName} ({$pEntry}){$flag}");
        }

        $this->line('');
        $this->line('=== Competition entry / standings counts ===');
        foreach (['ESP1', 'ESP2', 'ESP3A', 'ESP3B'] as $c) {
            $entries = CompetitionEntry::where('game_id', $gameId)
                ->where('competition_id', $c)->count();
            $standings = GameStanding::where('game_id', $gameId)
                ->where('competition_id', $c)->count();
            $this->line("  {$c}: entries={$entries}  standings={$standings}");
        }

        return self::SUCCESS;
    }
}

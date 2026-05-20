<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Modules\Season\Jobs\ProcessSeasonTransition;
use Illuminate\Console\Command;

/**
 * Re-dispatch ProcessSeasonTransition for a stuck game. Useful after running
 * app:unstick-game (or any manual state surgery) to resume the pipeline from
 * its checkpoint without going through the UI.
 */
class ResumeSeasonTransition extends Command
{
    protected $signature = 'app:resume-season-transition {game}';

    protected $description = 'Re-dispatch ProcessSeasonTransition for a stuck game.';

    public function handle(): int
    {
        $game = Game::find($this->argument('game'));

        if (!$game) {
            $this->error("Game {$this->argument('game')} not found.");
            return self::FAILURE;
        }

        if (!$game->isTransitioningSeason()) {
            $this->error('Game is not in a transitioning state (season_transitioning_at is null). Set season_transitioning_at first or trigger StartNewSeason via the UI.');
            return self::FAILURE;
        }

        $step = $game->season_transition_step ?? 'NULL';
        $this->line("Resuming game {$game->id} (season {$game->season}, step {$step})");

        ProcessSeasonTransition::dispatch($game->id);

        $this->info('ProcessSeasonTransition dispatched. Watch the queue worker / logs for progress.');
        return self::SUCCESS;
    }
}

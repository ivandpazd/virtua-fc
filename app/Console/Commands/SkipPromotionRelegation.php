<?php

namespace App\Console\Commands;

use App\Models\CompetitionEntry;
use App\Models\Game;
use App\Modules\Competition\Services\CountryConfig;
use App\Modules\Season\Processors\PromotionRelegationProcessor;
use App\Modules\Season\Services\SeasonClosingPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Escape hatch for games stuck at PromotionRelegationProcessor where the
 * upstream cross-rule filter (incomingByDivision) doesn't catch every
 * reserve/parent coexistence path. Advances season_transition_step past
 * PromotionRelegationProcessor so the resumed pipeline skips it, and
 * clears the country's supercup entries so the supercup field re-derives
 * cleanly on the next pipeline run (same pattern as unstick-game).
 *
 * Trade-off: this season has no automatic promotion/relegation. Use only
 * after confirming app:diagnose-stuck-game shows the game is otherwise
 * clean (no coexistence, no inversion, no missing standings, no
 * SimulatedSeason mismatches) — i.e. the only thing blocking progress is
 * the post-swap invariant assertion firing inside PromotionRelegationProcessor.
 *
 * Subsequent closing processors (reputation, fan loyalty, youth academy,
 * UEFA qualification) still run.
 */
class SkipPromotionRelegation extends Command
{
    protected $signature = 'app:skip-promotion-relegation {game} {--fix}';

    protected $description = 'Advance season_transition_step past PromotionRelegationProcessor for a stuck game.';

    public function handle(
        CountryConfig $countryConfig,
        SeasonClosingPipeline $pipeline,
    ): int {
        $game = Game::find($this->argument('game'));

        if (!$game) {
            $this->error("Game {$this->argument('game')} not found.");
            return self::FAILURE;
        }

        $targetStep = null;
        foreach ($pipeline->getProcessors() as $index => $processor) {
            if ($processor instanceof PromotionRelegationProcessor) {
                $targetStep = $index;
                break;
            }
        }

        if ($targetStep === null) {
            $this->error('Could not locate PromotionRelegationProcessor in the closing pipeline.');
            return self::FAILURE;
        }

        $currentStep = $game->season_transition_step;
        $this->line("Game {$game->id} (season {$game->season}, country {$game->country})");
        $this->line("Current step: " . ($currentStep ?? 'NULL') . " -> target step: {$targetStep}");

        if ($currentStep !== null && $currentStep >= $targetStep) {
            $this->info('Game has already passed PromotionRelegationProcessor — nothing to do.');
            return self::SUCCESS;
        }

        if (!$this->option('fix')) {
            $this->info('Dry run — re-run with --fix to apply.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($game, $targetStep, $countryConfig) {
            $game->updateQuietly(['season_transition_step' => $targetStep]);

            $country = $game->country ?? 'ES';
            $supercupConfig = $countryConfig->supercup($country);
            if ($supercupConfig !== null && !empty($supercupConfig['competition'])) {
                CompetitionEntry::where('game_id', $game->id)
                    ->where('competition_id', $supercupConfig['competition'])
                    ->delete();
            }
        });

        $this->info("Advanced season_transition_step to {$targetStep}. Run app:resume-season-transition to continue.");
        return self::SUCCESS;
    }
}

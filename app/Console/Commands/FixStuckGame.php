<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Modules\Season\Jobs\ProcessSeasonTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot recovery wrapper. For a game stuck mid season-transition:
 *
 *   1. Run app:unstick-game --fix     (consistency repairs: coexistence,
 *                                      inversion, missing standings,
 *                                      SimulatedSeason reconciliation)
 *   2. Run the pipeline synchronously (ProcessSeasonTransition::dispatchSync)
 *   3. If the pipeline crashes on the post-swap reserve-coexistence
 *      assertion in PromotionRelegationProcessor, run
 *      app:skip-promotion-relegation --fix and try the pipeline again.
 *   4. Surface the final status.
 *
 * Dry-run (default) runs the diagnostic + unstick-game dry-runs and
 * stops there. Pass --fix to actually apply changes and resume.
 *
 * Synchronous dispatch (not the queue) so the command can catch the
 * exception and decide whether to invoke the PR-skip escape hatch.
 */
class FixStuckGame extends Command
{
    protected $signature = 'app:fix-stuck-game {game} {--fix}';

    protected $description = 'Run all repair steps for a stuck game and resume the pipeline.';

    public function handle(): int
    {
        $gameId = $this->argument('game');
        $apply = (bool) $this->option('fix');

        $game = Game::find($gameId);
        if (!$game) {
            $this->error("Game {$gameId} not found.");
            return self::FAILURE;
        }

        if (!$apply) {
            $this->line('=== Diagnostic ===');
            Artisan::call('app:diagnose-stuck-game', ['game' => $gameId], $this->output);
            $this->line('');
            $this->line('=== unstick-game dry-run ===');
            Artisan::call('app:unstick-game', ['--game' => $gameId], $this->output);
            $this->line('');
            $this->info('Dry run complete. Re-run with --fix to apply repairs and resume the pipeline.');
            return self::SUCCESS;
        }

        $this->line('=== Step 1: app:unstick-game --fix ===');
        Artisan::call('app:unstick-game', ['--game' => $gameId, '--fix' => true], $this->output);

        $this->line('');
        $this->line('=== Step 2: resume pipeline (sync) ===');
        $result = $this->runPipeline($gameId);

        if ($result === self::SUCCESS) {
            $this->info('Pipeline completed successfully — game unstuck.');
            return self::SUCCESS;
        }

        if ($result !== 'COEXISTENCE') {
            return self::FAILURE;
        }

        $this->warn('Pipeline hit the post-swap reserve-coexistence assertion. Falling back to skip-promotion-relegation.');

        $this->line('');
        $this->line('=== Step 3: app:skip-promotion-relegation --fix ===');
        Artisan::call('app:skip-promotion-relegation', ['game' => $gameId, '--fix' => true], $this->output);

        $this->line('');
        $this->line('=== Step 4: resume pipeline again (sync) ===');
        $result2 = $this->runPipeline($gameId);

        if ($result2 === self::SUCCESS) {
            $this->info('Pipeline completed successfully after skipping promotion/relegation — game unstuck.');
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * Run the season-transition job synchronously. Returns SUCCESS on clean
     * completion, the literal 'COEXISTENCE' marker if the failure was the
     * post-swap reserve-coexistence assertion, FAILURE for any other error.
     *
     * @return int|string
     */
    private function runPipeline(string $gameId)
    {
        try {
            ProcessSeasonTransition::dispatchSync($gameId);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Pipeline failed: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'Reserve/parent coexistence invariant violated')) {
                return 'COEXISTENCE';
            }

            return self::FAILURE;
        }
    }
}

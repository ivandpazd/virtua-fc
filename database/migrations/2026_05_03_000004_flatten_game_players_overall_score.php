<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flatten dual abilities into `overall_score` on `game_players`.
 *
 * `game_players` is the largest table in the system (~21M rows in production).
 * A single in-transaction `UPDATE … SET overall_score = ROUND(...)` would
 * rewrite every row under one AccessExclusiveLock and block writes for the
 * entire run. To stay online:
 *
 *   - withinTransaction = false: each statement commits independently, so the
 *     ADD COLUMN commits before the backfill loop starts and each batch
 *     releases its row locks immediately.
 *   - Backfill runs in 50k-row batches, keyed by primary key. Each batch is
 *     a short, bounded UPDATE that competes only briefly for row locks.
 *   - Column stays nullable (matching the original `game_technical_ability`
 *     column), so we don't need a `SET NOT NULL` rewrite at the end.
 *   - Final `dropColumn` is metadata-only on PostgreSQL, so the brief
 *     AccessExclusiveLock is acceptable.
 *
 * Safe to re-run after a partial failure: the column-existence guards
 * short-circuit completed steps, and the backfill loop's `WHERE overall_score
 * IS NULL` predicate naturally excludes already-processed rows.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const BATCH_SIZE = 50_000;

    public function up(): void
    {
        if (! Schema::hasColumn('game_players', 'overall_score')) {
            Schema::table('game_players', function (Blueprint $table) {
                $table->unsignedTinyInteger('overall_score')->nullable();
            });
        }

        if (Schema::hasColumn('game_players', 'game_technical_ability')) {
            $this->backfillInBatches();

            Schema::table('game_players', function (Blueprint $table) {
                $table->dropColumn(['game_technical_ability', 'game_physical_ability']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('game_players', 'game_technical_ability')) {
            Schema::table('game_players', function (Blueprint $table) {
                $table->unsignedTinyInteger('game_technical_ability')->nullable();
                $table->unsignedTinyInteger('game_physical_ability')->nullable();
            });

            // Lossy round-trip: the asymmetry between technique and physique
            // is permanently gone. Both halves get the flattened value back.
            $this->reverseBackfillInBatches();

            Schema::table('game_players', function (Blueprint $table) {
                $table->dropColumn('overall_score');
            });
        }
    }

    private function backfillInBatches(): void
    {
        do {
            $updated = DB::update('
                UPDATE game_players
                SET overall_score = ROUND((game_technical_ability + game_physical_ability) / 2.0)
                WHERE id IN (
                    SELECT id FROM game_players
                    WHERE overall_score IS NULL
                      AND game_technical_ability IS NOT NULL
                      AND game_physical_ability IS NOT NULL
                    LIMIT ' . self::BATCH_SIZE . '
                    FOR UPDATE SKIP LOCKED
                )
            ');
        } while ($updated > 0);
    }

    private function reverseBackfillInBatches(): void
    {
        do {
            $updated = DB::update('
                UPDATE game_players
                SET game_technical_ability = overall_score,
                    game_physical_ability = overall_score
                WHERE id IN (
                    SELECT id FROM game_players
                    WHERE game_technical_ability IS NULL
                      AND overall_score IS NOT NULL
                    LIMIT ' . self::BATCH_SIZE . '
                    FOR UPDATE SKIP LOCKED
                )
            ');
        } while ($updated > 0);
    }
};

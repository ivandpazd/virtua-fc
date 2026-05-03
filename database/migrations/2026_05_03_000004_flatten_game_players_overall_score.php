<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `overall_score` column to `game_players`. No backfill, no drop.
 *
 * The original plan was to backfill ROUND((game_technical_ability +
 * game_physical_ability) / 2.0) for all ~21M existing rows and then drop the
 * dual-ability columns. On Neon Postgres the random-access UPDATE pattern
 * (UUID PK + 10 indexes, ~5% HOT updates, no compute scaling available)
 * proved too slow to fit any maintenance window.
 *
 * Instead, this migration only ensures the new column exists. The
 * `GamePlayer::getOverallScoreAttribute()` accessor handles the partial-state
 * data: if `overall_score` is NULL on a row, it computes the value on-read
 * from the still-present `game_technical_ability` / `game_physical_ability`
 * columns. New rows written by the application populate `overall_score`
 * directly, so the NULL pool only shrinks over time.
 *
 * The eventual cleanup — backfill in the background, then drop the dual
 * columns — is deferred to a follow-up migration once the prod table can
 * tolerate it (e.g. via pg_repack or a different access pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('game_players', 'overall_score')) {
            Schema::table('game_players', function (Blueprint $table) {
                $table->unsignedTinyInteger('overall_score')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('game_players', 'overall_score')) {
            Schema::table('game_players', function (Blueprint $table) {
                $table->dropColumn('overall_score');
            });
        }
    }
};

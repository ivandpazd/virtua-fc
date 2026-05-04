<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy `game_technical_ability` / `game_physical_ability` columns
 * from `game_players`. Follow-up to 2026_05_03_000004, which added
 * `overall_score` but deferred the column drop because the in-migration
 * backfill couldn't fit a Neon maintenance window. The backfill ran
 * out-of-band via `app:backfill-overall-scores` (one
 * `BackfillGameOverallScores` job per game), so `overall_score` is now
 * populated and the dual columns are no longer read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            if (Schema::hasColumn('game_players', 'game_technical_ability')) {
                $table->dropColumn('game_technical_ability');
            }
            if (Schema::hasColumn('game_players', 'game_physical_ability')) {
                $table->dropColumn('game_physical_ability');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table) {
            if (! Schema::hasColumn('game_players', 'game_technical_ability')) {
                $table->unsignedTinyInteger('game_technical_ability')->nullable()->after('morale');
            }
            if (! Schema::hasColumn('game_players', 'game_physical_ability')) {
                $table->unsignedTinyInteger('game_physical_ability')->nullable()->after('game_technical_ability');
            }
        });
    }
};

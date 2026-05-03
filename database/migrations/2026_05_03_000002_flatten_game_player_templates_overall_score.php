<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flatten dual abilities into `overall_score` on `game_player_templates`.
 *
 * Templates are per-season per-team rosters — small in row count, so a
 * standard transactional migration is fine. Column stays nullable to match
 * the original create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('game_player_templates', 'overall_score')) {
            Schema::table('game_player_templates', function (Blueprint $table) {
                $table->unsignedTinyInteger('overall_score')->nullable();
            });
        }

        if (Schema::hasColumn('game_player_templates', 'game_technical_ability')) {
            DB::statement('UPDATE game_player_templates SET overall_score = ROUND((game_technical_ability + game_physical_ability) / 2.0) WHERE overall_score IS NULL AND game_technical_ability IS NOT NULL AND game_physical_ability IS NOT NULL');

            Schema::table('game_player_templates', function (Blueprint $table) {
                $table->dropColumn(['game_technical_ability', 'game_physical_ability']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('game_player_templates', 'game_technical_ability')) {
            Schema::table('game_player_templates', function (Blueprint $table) {
                $table->unsignedTinyInteger('game_technical_ability')->nullable();
                $table->unsignedTinyInteger('game_physical_ability')->nullable();
            });
            DB::statement('UPDATE game_player_templates SET game_technical_ability = overall_score, game_physical_ability = overall_score WHERE overall_score IS NOT NULL');

            Schema::table('game_player_templates', function (Blueprint $table) {
                $table->dropColumn('overall_score');
            });
        }
    }
};

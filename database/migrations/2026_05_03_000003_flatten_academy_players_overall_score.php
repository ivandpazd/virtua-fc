<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flatten dual abilities into `overall_score` (and `initial_overall`) on
 * `academy_players`. Small per-game youth roster table, so a standard
 * transactional migration is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('academy_players', 'overall_score')) {
            Schema::table('academy_players', function (Blueprint $table) {
                $table->unsignedTinyInteger('overall_score')->nullable();
            });
        }

        if (! Schema::hasColumn('academy_players', 'initial_overall')) {
            Schema::table('academy_players', function (Blueprint $table) {
                $table->unsignedTinyInteger('initial_overall')->nullable();
            });
        }

        if (Schema::hasColumn('academy_players', 'technical_ability')) {
            DB::statement('UPDATE academy_players SET overall_score = ROUND((technical_ability + physical_ability) / 2.0) WHERE overall_score IS NULL');
            DB::statement('UPDATE academy_players SET initial_overall = ROUND((initial_technical + initial_physical) / 2.0) WHERE initial_overall IS NULL AND initial_technical IS NOT NULL AND initial_physical IS NOT NULL');

            Schema::table('academy_players', function (Blueprint $table) {
                $table->dropColumn(['technical_ability', 'physical_ability', 'initial_technical', 'initial_physical']);
            });
        }

        DB::statement('UPDATE academy_players SET overall_score = 50 WHERE overall_score IS NULL');
        DB::statement('ALTER TABLE academy_players ALTER COLUMN overall_score SET NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('academy_players', 'technical_ability')) {
            Schema::table('academy_players', function (Blueprint $table) {
                $table->unsignedTinyInteger('technical_ability')->default(50);
                $table->unsignedTinyInteger('physical_ability')->default(50);
                $table->unsignedTinyInteger('initial_technical')->nullable();
                $table->unsignedTinyInteger('initial_physical')->nullable();
            });
            DB::statement('UPDATE academy_players SET technical_ability = overall_score, physical_ability = overall_score, initial_technical = initial_overall, initial_physical = initial_overall');

            Schema::table('academy_players', function (Blueprint $table) {
                $table->dropColumn(['overall_score', 'initial_overall']);
            });
        }
    }
};

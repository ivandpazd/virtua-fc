<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flatten the dual-axis (technical_ability + physical_ability) ability model
 * on the `players` reference table into a single `overall_score` column.
 *
 * The codebase was already half-flattened: valuation, formation selection
 * and goal-scorer weighting all collapsed the two values to an average.
 * This migration makes the underlying schema match.
 *
 * `players` is small (~tens of thousands of rows), so a standard transactional
 * migration is fine. The column-existence guards make the migration idempotent
 * if a previous attempt was interrupted halfway through.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('players', 'overall_score')) {
            Schema::table('players', function (Blueprint $table) {
                $table->unsignedTinyInteger('overall_score')->nullable();
            });
        }

        if (Schema::hasColumn('players', 'technical_ability')) {
            DB::statement('UPDATE players SET overall_score = ROUND((technical_ability + physical_ability) / 2.0) WHERE overall_score IS NULL');

            Schema::table('players', function (Blueprint $table) {
                $table->dropColumn(['technical_ability', 'physical_ability']);
            });
        }

        // After backfill the column is populated for every row, so make it
        // NOT NULL with the same default as the original create migration.
        DB::statement('ALTER TABLE players ALTER COLUMN overall_score SET DEFAULT 50');
        DB::statement('UPDATE players SET overall_score = 50 WHERE overall_score IS NULL');
        DB::statement('ALTER TABLE players ALTER COLUMN overall_score SET NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('players', 'technical_ability')) {
            // Lossy round-trip: the asymmetry between technique and physique
            // is permanently gone. Both halves get the flattened value back.
            Schema::table('players', function (Blueprint $table) {
                $table->unsignedTinyInteger('technical_ability')->default(50);
                $table->unsignedTinyInteger('physical_ability')->default(50);
            });
            DB::statement('UPDATE players SET technical_ability = overall_score, physical_ability = overall_score');

            Schema::table('players', function (Blueprint $table) {
                $table->dropColumn('overall_score');
            });
        }
    }
};

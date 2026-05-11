<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-game tenant tables follow the in-game calendar (Game::current_date),
 * not wall-clock time. match_events ordering is driven by the in-match
 * `minute` column, and no code reads created_at — drop it to align with
 * the rest of the tenant-plane schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_events', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('match_events', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent();
        });
    }
};

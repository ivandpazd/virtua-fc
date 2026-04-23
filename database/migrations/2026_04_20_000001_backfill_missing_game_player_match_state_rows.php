<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Historical no-op.
     *
     * This slot used to run a full-table backfill of game_player_match_state
     * for every existing game_player. On large databases that INSERT ran long
     * enough to exceed the deploy timeout, so the work was moved to an
     * out-of-band artisan command which has since been run and removed.
     *
     * The file remains so environments that already recorded this migration
     * don't see it as "pending" — removing it would break the migrations
     * table invariant in those databases.
     */
    public function up(): void
    {
        // intentionally empty — see class docblock
    }

    public function down(): void
    {
        // intentionally empty — see class docblock
    }
};

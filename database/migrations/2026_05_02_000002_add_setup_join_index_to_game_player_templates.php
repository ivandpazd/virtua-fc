<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Composite index for SetupNewGame's match-state seed query, which
        // joins game_players back to templates on (player_id, team_id, season)
        // to copy fitness/morale. The existing single-column season index
        // forces a hash join over every template in the season; this index
        // lets the planner do an index lookup per row.
        DB::statement(<<<'SQL'
            CREATE INDEX game_player_templates_season_team_player_index
            ON game_player_templates (season, team_id, player_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS game_player_templates_season_team_player_index');
    }
};

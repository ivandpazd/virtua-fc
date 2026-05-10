<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Career records with joined_from='Academy' that actually came in as
        // free-agent signings (game_transfers row of type='free_agent' for the
        // same player) were misattributed because the old free-agent path
        // didn't distinguish "no previous team" from academy graduates.
        DB::table('user_squad_career_records')
            ->where('joined_from', 'Academy')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('game_transfers')
                    ->whereColumn('game_transfers.game_player_id', 'user_squad_career_records.game_player_id')
                    ->where('game_transfers.type', 'free_agent');
            })
            ->update(['joined_from' => 'FreeAgent']);
    }

    public function down(): void
    {
        DB::table('user_squad_career_records')
            ->where('joined_from', 'FreeAgent')
            ->update(['joined_from' => 'Academy']);
    }
};

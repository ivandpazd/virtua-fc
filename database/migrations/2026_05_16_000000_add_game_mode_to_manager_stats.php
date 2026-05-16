<?php

use App\Models\Game;
use App\Models\ManagerStats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_stats', function (Blueprint $table) {
            $table->string('game_mode', 16)->nullable()->after('team_id');
            $table->index('game_mode');
        });

        // Pro Manager mode is introduced in this deployment, so every existing
        // manager_stats row predates it and is a regular career. One UPDATE,
        // no cross-plane lookup.
        ManagerStats::query()
            ->whereNull('game_mode')
            ->update(['game_mode' => Game::MODE_CAREER]);
    }

    public function down(): void
    {
        Schema::table('manager_stats', function (Blueprint $table) {
            $table->dropIndex(['game_mode']);
            $table->dropColumn('game_mode');
        });
    }
};

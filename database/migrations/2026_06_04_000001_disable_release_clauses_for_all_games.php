<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('games')->update(['release_clauses_enabled' => false]);
    }

    public function down(): void
    {
        // Intentionally left empty — we don't track which games had release
        // clauses enabled before this migration, so the change can't be reversed.
    }
};

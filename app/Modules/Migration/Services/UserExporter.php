<?php

namespace App\Modules\Migration\Services;

use App\Modules\Migration\TableManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produces the JSON envelope that the export side ships to the import side.
 *
 * The shape is intentionally dumb: raw column-value rows, no Eloquent
 * accessors, no transformation. The import side uses DB::insert with the same
 * shape so a Postgres → Postgres round-trip is byte-equivalent for plain
 * columns and string-equivalent for json/jsonb columns (Postgres re-parses
 * them on insert).
 */
class UserExporter
{
    /** @return array<string, mixed> */
    public function export(int $userId): array
    {
        $user = $this->row('pgsql_control', 'users', 'id', $userId);
        if ($user === null) {
            throw new \RuntimeException("User {$userId} not found on the export side.");
        }

        $controlPlane = ['users' => [$user]];
        foreach (TableManifest::CONTROL_PLANE_TABLES as $table => $meta) {
            if ($table === 'users') {
                continue;
            }
            $controlPlane[$table] = DB::connection('pgsql_control')
                ->table($table)
                ->where($meta['key'], $userId)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $gameIds = DB::table('games')->where('user_id', $userId)->pluck('id')->all();
        $games = array_map(fn (string $gameId) => $this->exportGame($gameId), $gameIds);

        return [
            'format_version' => 1,
            'exported_at' => now()->toAtomString(),
            'user_id' => $userId,
            'control_plane' => $controlPlane,
            'games' => $games,
        ];
    }

    /** @return array<string, mixed> */
    private function exportGame(string $gameId): array
    {
        $tables = [];
        foreach (TableManifest::TENANT_TABLES_IN_INSERT_ORDER as $table) {
            $tables[$table] = $this->rowsForGame($table, $gameId);
        }

        return [
            'game_id' => $gameId,
            'tables' => $tables,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rowsForGame(string $table, string $gameId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        // Every tenant table in the manifest carries a direct game_id column;
        // the child-of-child tables (match_events, game_player_match_state,
        // financial_transactions) had it added in dedicated migrations
        // precisely so a single-column filter can dump them.
        return DB::table($table)
            ->where('game_id', $gameId)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function row(string $connection, string $table, string $key, mixed $value): ?array
    {
        $row = DB::connection($connection)->table($table)->where($key, $value)->first();

        return $row ? (array) $row : null;
    }
}

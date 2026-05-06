<?php

namespace Tests\Feature\Migration;

use App\Models\Competition;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Modules\Migration\Services\UserExporter;
use App\Modules\Migration\Services\UserImporter;
use App\Modules\Migration\TableManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end test for the migration export → import flow.
 *
 * The bug this catches: an earlier revision of UserExporter looked up tables
 * via Schema::getTableListing() (default schemaQualified=true), which returns
 * "public.games" not "games". Every existence check missed, so per-game
 * payloads shipped with all-empty `tables` arrays. The job completed fast
 * and reported success while transferring zero rows.
 */
class ExportImportRoundtripTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_manifest_lists_every_game_for_user(): void
    {
        $user = User::factory()->create();
        $game1 = Game::factory()->create(['user_id' => $user->id]);
        $game2 = Game::factory()->create(['user_id' => $user->id]);
        // Decoy game owned by another user — must NOT appear in this user's manifest.
        Game::factory()->create();

        $manifest = (new UserExporter())->exportManifest($user->id);

        $this->assertSame(UserExporter::FORMAT_VERSION, $manifest['format_version']);
        $this->assertSame($user->id, $manifest['user_id']);
        $this->assertEqualsCanonicalizing(
            [$game1->id, $game2->id],
            $manifest['game_ids']
        );
        $this->assertSame(1, count($manifest['control_plane']['users']));
        $this->assertSame($user->id, $manifest['control_plane']['users'][0]['id']);
    }

    public function test_export_game_returns_actual_rows_not_empty_arrays(): void
    {
        // This is the critical regression test for the schemaQualified bug:
        // if tenantTableExists() is broken, every table will be `[]` even
        // when the database has rows.
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $game = Game::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);

        $matches = GameMatch::factory()->forGame($game)->count(3)->create();
        $players = GamePlayer::factory()->forGame($game)->forTeam($team)->count(5)->create();

        $payload = (new UserExporter())->exportGame($user->id, $game->id);

        $this->assertSame(UserExporter::FORMAT_VERSION, $payload['format_version']);
        $this->assertSame($game->id, $payload['game_id']);

        // The games table itself must contain the root row.
        $this->assertCount(1, $payload['tables']['games']);
        $this->assertSame($game->id, $payload['tables']['games'][0]['id']);

        // game_matches and game_players must contain the rows we created.
        $this->assertCount(3, $payload['tables']['game_matches']);
        $this->assertCount(5, $payload['tables']['game_players']);

        $this->assertEqualsCanonicalizing(
            $matches->pluck('id')->all(),
            array_column($payload['tables']['game_matches'], 'id')
        );
        $this->assertEqualsCanonicalizing(
            $players->pluck('id')->all(),
            array_column($payload['tables']['game_players'], 'id')
        );
    }

    public function test_export_game_refuses_to_dump_a_game_belonging_to_someone_else(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $game = Game::factory()->create(['user_id' => $owner->id]);

        $this->expectException(\RuntimeException::class);
        (new UserExporter())->exportGame($stranger->id, $game->id);
    }

    public function test_full_roundtrip_restores_every_row(): void
    {
        // Set up data on the export side.
        $user = User::factory()->create(['name' => 'Original Name']);
        $team = Team::factory()->create();
        $game = Game::factory()->create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'season' => '2025',
            'player_name' => 'Mister Manager',
        ]);
        GameMatch::factory()->forGame($game)->count(3)->create();
        GamePlayer::factory()->forGame($game)->forTeam($team)->count(5)->create();

        // Snapshot per-table row counts so we can compare after the roundtrip.
        $snapshot = $this->snapshotGame($game->id);
        $this->assertSame(1, $snapshot['games']);
        $this->assertSame(3, $snapshot['game_matches']);
        $this->assertSame(5, $snapshot['game_players']);

        // Export.
        $exporter = new UserExporter();
        $manifest = $exporter->exportManifest($user->id);
        $perGame = [];
        foreach ($manifest['game_ids'] as $gameId) {
            $perGame[$gameId] = $exporter->exportGame($user->id, $gameId);
        }

        // Wipe per-game rows. We don't wipe the user (importControlPlane
        // upserts on existing rows) because the import-side has already
        // received a copy of the user via the pre-import bulk copy.
        $this->wipeGame($game->id);
        $this->assertSame(0, DB::table('games')->where('id', $game->id)->count());
        $this->assertSame(0, DB::table('game_matches')->where('game_id', $game->id)->count());
        $this->assertSame(0, DB::table('game_players')->where('game_id', $game->id)->count());

        // Import.
        $importer = new UserImporter();
        $importer->importControlPlane($user->id, $manifest['control_plane']);
        foreach ($perGame as $payload) {
            $importer->importGame($payload);
        }

        // Verify.
        $restored = $this->snapshotGame($game->id);
        $this->assertSame($snapshot, $restored, 'Roundtrip changed per-table row counts');

        // Spot-check that columns survived intact.
        $reloadedGame = DB::table('games')->where('id', $game->id)->first();
        $this->assertSame('Mister Manager', $reloadedGame->player_name);
        $this->assertSame('2025', $reloadedGame->season);
    }

    public function test_roundtrip_handles_cross_table_fk_within_a_game(): void
    {
        // Regression: game_matches.mvp_player_id is an FK to game_players.id.
        // If the manifest insert order puts game_matches before game_players,
        // the importer trips a foreign-key violation on insert.
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $game = Game::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);

        $player = GamePlayer::factory()->forGame($game)->forTeam($team)->create();
        $match = GameMatch::factory()->forGame($game)->create([
            'mvp_player_id' => $player->id,
        ]);

        $payload = (new UserExporter())->exportGame($user->id, $game->id);

        $this->wipeGame($game->id);

        // Must not throw — the importer must insert game_players before
        // game_matches so the mvp_player_id FK resolves.
        (new UserImporter())->importGame($payload);

        $reloaded = DB::table('game_matches')->where('id', $match->id)->first();
        $this->assertNotNull($reloaded);
        $this->assertSame($player->id, $reloaded->mvp_player_id);
    }

    public function test_import_game_is_idempotent_on_retry(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $game = Game::factory()->create(['user_id' => $user->id, 'team_id' => $team->id]);
        GameMatch::factory()->forGame($game)->count(2)->create();

        $payload = (new UserExporter())->exportGame($user->id, $game->id);

        // Wipe and import twice — the second call must not fail on
        // duplicate-key violations (wipeGame wipes before reinserting).
        $this->wipeGame($game->id);

        $importer = new UserImporter();
        $importer->importGame($payload);
        $importer->importGame($payload); // retry

        $this->assertSame(1, DB::table('games')->where('id', $game->id)->count());
        $this->assertSame(2, DB::table('game_matches')->where('game_id', $game->id)->count());
    }

    /**
     * @return array<string, int>
     */
    private function snapshotGame(string $gameId): array
    {
        $counts = [];
        foreach (TableManifest::TENANT_TABLES_IN_INSERT_ORDER as $table) {
            $column = $table === 'games' ? 'id' : 'game_id';
            $counts[$table] = DB::table($table)->where($column, $gameId)->count();
        }

        return $counts;
    }

    private function wipeGame(string $gameId): void
    {
        foreach (array_reverse(TableManifest::TENANT_TABLES_IN_INSERT_ORDER) as $table) {
            $column = $table === 'games' ? 'id' : 'game_id';
            DB::table($table)->where($column, $gameId)->delete();
        }
    }
}

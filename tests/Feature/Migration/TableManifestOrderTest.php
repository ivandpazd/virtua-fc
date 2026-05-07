<?php

namespace Tests\Feature\Migration;

use App\Modules\Migration\TableManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Structural tests for the migration manifest's insert order.
 *
 * The job inserts data in this order:
 *
 *   1. Every table in TENANT_TABLES_IN_INSERT_ORDER (per game, per row).
 *   2. Every table in CONTROL_PLANE_TABLES.
 *
 * For every real foreign-key constraint between two tables in the manifest,
 * the parent table must come at or before the child table in that combined
 * order — otherwise the importer trips the FK on insert. This is
 * independent of whether any factory exercises the relationship, so it
 * protects against a new migration adding a cross-table FK without
 * updating the manifest order or the job.
 */
class TableManifestOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_order_is_consistent_with_every_fk_between_listed_tables(): void
    {
        $tenantOrder = TableManifest::TENANT_TABLES_IN_INSERT_ORDER;
        $controlOrder = array_keys(TableManifest::CONTROL_PLANE_TABLES);

        // Combined order: tenant first, then control-plane. Position is a
        // global integer; child position must be >= parent position.
        $combined = array_merge($tenantOrder, $controlOrder);
        $position = array_flip($combined);

        $fks = DB::select(
            <<<'SQL'
            SELECT
                tc.table_name      AS child_table,
                kcu.column_name    AS child_column,
                ccu.table_name     AS parent_table,
                ccu.column_name    AS parent_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema  = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema  = ccu.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema    = current_schema()
              AND tc.table_name      = ANY(?)
              AND ccu.table_name     = ANY(?)
            SQL,
            [
                '{'.implode(',', $combined).'}',
                '{'.implode(',', $combined).'}',
            ],
        );

        $this->assertNotEmpty($fks, 'Expected to find FKs between manifest tables; query returned none.');

        $violations = [];
        foreach ($fks as $fk) {
            // Self-referential FKs are inserted in one statement so the
            // manifest position is irrelevant.
            if ($fk->child_table === $fk->parent_table) {
                continue;
            }
            // The user row pre-exists on the import side (bulk-copied
            // before the cutover), so FKs whose parent is `users` are
            // satisfied at job start and don't constrain the order.
            if ($fk->parent_table === 'users') {
                continue;
            }
            $childPos = $position[$fk->child_table] ?? null;
            $parentPos = $position[$fk->parent_table] ?? null;
            if ($childPos === null || $parentPos === null) {
                continue;
            }
            if ($parentPos > $childPos) {
                $violations[] = sprintf(
                    '%s.%s -> %s.%s: parent comes after child in import order',
                    $fk->child_table,
                    $fk->child_column,
                    $fk->parent_table,
                    $fk->parent_column,
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Migration import order violates one or more FK orderings:\n  - "
                .implode("\n  - ", $violations),
        );
    }
}

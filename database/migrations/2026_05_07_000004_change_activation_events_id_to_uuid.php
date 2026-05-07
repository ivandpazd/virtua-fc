<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Same fix as simulated_seasons / manager_trophies: a bigint autoincrement PK
 * collides on the import side of the beta→prod migration when two users on
 * different beta databases happen to share an id. Nothing FKs into
 * activation_events.id and no app code reads it externally.
 *
 * The column keeps a DB-side `DEFAULT gen_random_uuid()` because
 * ActivationTracker::record() inserts via `insertOrIgnore`, which bypasses
 * Eloquent's HasUuids `creating` listener — without the default, those
 * inserts would fail the NOT NULL PK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE activation_events DROP CONSTRAINT activation_events_pkey');
        DB::statement('ALTER TABLE activation_events ALTER COLUMN id DROP DEFAULT');
        DB::statement('DROP SEQUENCE IF EXISTS activation_events_id_seq');
        DB::statement('ALTER TABLE activation_events ALTER COLUMN id TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE activation_events ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE activation_events ADD PRIMARY KEY (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE activation_events DROP CONSTRAINT activation_events_pkey');
        DB::statement('ALTER TABLE activation_events ALTER COLUMN id DROP DEFAULT');
        DB::statement('CREATE SEQUENCE activation_events_id_seq');
        DB::statement('ALTER TABLE activation_events ALTER COLUMN id TYPE bigint USING (nextval(\'activation_events_id_seq\'))');
        DB::statement('ALTER TABLE activation_events ALTER COLUMN id SET DEFAULT nextval(\'activation_events_id_seq\')');
        DB::statement('ALTER SEQUENCE activation_events_id_seq OWNED BY activation_events.id');
        DB::statement('SELECT setval(\'activation_events_id_seq\', COALESCE((SELECT MAX(id) FROM activation_events), 1))');
        DB::statement('ALTER TABLE activation_events ADD PRIMARY KEY (id)');
    }
};

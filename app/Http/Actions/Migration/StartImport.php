<?php

namespace App\Http\Actions\Migration;

use App\Jobs\MigrationImportJob;
use App\Modules\Migration\MigrationStatus;
use App\Modules\Migration\Services\MigrationProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Import side. POST /migration/import — kicks off the queued import job after
 * an atomic CAS that flips the user from `pending` to `in_progress`.
 *
 * Idempotency: a second click while the job runs is a 409. A click after the
 * import completed redirects to dashboard. A click after a failure flips the
 * status back to `pending` so the user can retry from the same page.
 */
class StartImport
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        // Treat `failed` as retryable: reset to `pending` first so the CAS
        // below promotes it to `in_progress`.
        if ($user->migration_status === MigrationStatus::FAILED) {
            DB::connection('pgsql_control')
                ->table('users')
                ->where('id', $user->id)
                ->where('migration_status', MigrationStatus::FAILED->value)
                ->update(['migration_status' => MigrationStatus::PENDING->value]);
        }

        $rowsAffected = DB::connection('pgsql_control')
            ->table('users')
            ->where('id', $user->id)
            ->where('migration_status', MigrationStatus::PENDING->value)
            ->update(['migration_status' => MigrationStatus::IN_PROGRESS->value]);

        if ($rowsAffected === 0) {
            $user->refresh();
            $message = match ($user->migration_status) {
                MigrationStatus::COMPLETED => __('migration.import_already_completed'),
                MigrationStatus::IN_PROGRESS => __('migration.import_already_in_progress'),
                default => __('migration.import_failed'),
            };

            return response()->json([
                'status' => $user->migration_status->value,
                'message' => $message,
            ], 409);
        }

        MigrationProgress::set($user->id, 0, 'starting');
        MigrationImportJob::dispatch($user->id);

        return response()->json([
            'status' => MigrationStatus::IN_PROGRESS->value,
        ], 202);
    }
}

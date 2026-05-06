<?php

namespace App\Http\Views\Migration;

use App\Modules\Migration\MigrationStatus;
use Illuminate\Http\Request;

/**
 * Import side. GET /migration/import — landing page after the handoff.
 * Renders one of:
 *
 *   - "Copy my data" CTA (status=pending)
 *   - Progress bar polling /migration/status (status=in_progress)
 *   - "Already migrated, continue" (status=completed)
 *   - Retry button + error (status=failed)
 *
 * The page itself uses Alpine to drive the polling; this view just supplies
 * the initial state and the URL endpoints.
 */
class ShowImport
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->route('login');
        }

        return view('migration.import', [
            'status' => $user->migration_status,
            'statusValues' => [
                'pending' => MigrationStatus::PENDING->value,
                'in_progress' => MigrationStatus::IN_PROGRESS->value,
                'completed' => MigrationStatus::COMPLETED->value,
                'failed' => MigrationStatus::FAILED->value,
            ],
            'startUrl' => route('migration.import.start'),
            'statusUrl' => route('migration.import.status'),
            'dashboardUrl' => route('dashboard'),
        ]);
    }
}

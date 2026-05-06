<?php

namespace App\Http\Actions\Migration;

use App\Modules\Migration\MigrationGate;
use App\Modules\Migration\MigrationStatus;
use App\Modules\Migration\Services\SignedHandoff;
use App\Modules\Migration\TokenPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Export side. Banner click handler.
 *
 * Mints a short-lived handoff token bound to the logged-in user and 302s the
 * browser to the import side's land URL. The session cookie does NOT carry
 * over (different sub-domain, different Redis); the token is the only proof
 * of identity that crosses the boundary.
 */
class StartMigration
{
    public function __construct(
        private readonly SignedHandoff $handoff,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        // During a smoke test, only allow-listed users may proceed. Match the
        // banner: if the gate hides the CTA, the route shouldn't be reachable
        // by guessing the URL.
        abort_unless(MigrationGate::isUserAllowed($user->id), 404);

        // Already migrated → no point re-handoff. Send them to the destination
        // anyway so they end up where the data actually lives.
        $destination = (string) config('migration.destination_url', '');
        abort_if($destination === '', 503, 'Migration destination is not configured.');

        if ($user->migration_status === MigrationStatus::COMPLETED) {
            return redirect()->away($destination);
        }

        $token = $this->handoff->mint(
            TokenPurpose::HANDOFF,
            $user->id,
            (int) config('migration.handoff_ttl', 60),
        );

        $url = rtrim($destination, '/').'/migration/land?t='.urlencode($token);

        return redirect()->away($url);
    }
}

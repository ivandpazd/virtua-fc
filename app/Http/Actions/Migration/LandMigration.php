<?php

namespace App\Http\Actions\Migration;

use App\Models\User;
use App\Modules\Migration\Exceptions\InvalidHandoffToken;
use App\Modules\Migration\Services\SignedHandoff;
use App\Modules\Migration\TokenPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Import side. Receives the user redirected from the export side's banner.
 *
 *   1. Verifies the handoff token from the URL (HMAC + expiry).
 *   2. Looks up the user by id; the user row is expected to exist locally
 *      because reference data + control-plane seeding has happened up-front.
 *      If it doesn't, we return a friendly error rather than auto-creating —
 *      auto-creation hides config bugs that are easier to fix once.
 *   3. Logs the user in via the standard session guard. The session is
 *      regenerated to prevent fixation.
 *   4. Redirects to the import flow.
 */
class LandMigration
{
    public function __construct(
        private readonly SignedHandoff $handoff,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $token = (string) $request->query('t', '');
        if ($token === '') {
            return $this->failure(__('migration.handoff_invalid'));
        }

        try {
            $verified = $this->handoff->verify(TokenPurpose::HANDOFF, $token);
        } catch (InvalidHandoffToken) {
            return $this->failure(__('migration.handoff_invalid'));
        }

        $user = User::find($verified->userId);
        if ($user === null) {
            return $this->failure(__('migration.handoff_user_missing'));
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('migration.import.show');
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}

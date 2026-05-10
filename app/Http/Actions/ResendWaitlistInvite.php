<?php

namespace App\Http\Actions;

use App\Models\WaitlistEntry;
use App\Services\BetaInviteService;
use Illuminate\Http\Request;

class ResendWaitlistInvite
{
    public function __invoke(Request $request, WaitlistEntry $waitlistEntry, BetaInviteService $inviteService)
    {
        if (! config('beta.enabled')) {
            return back()->with('error', __('admin.waitlist_beta_disabled'));
        }

        $invite = $waitlistEntry->inviteCode;

        if (! $invite) {
            return back()->with('error', __('admin.waitlist_resend_no_invite'));
        }

        if ($invite->times_used > 0) {
            return back()->with('error', __('admin.waitlist_resend_already_registered'));
        }

        $inviteService->resend($invite);

        return back()->with('success', __('admin.waitlist_invite_resent'));
    }
}

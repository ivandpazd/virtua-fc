<?php

namespace App\Http\Middleware;

use App\Modules\Migration\MigrationStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the import-side deployment, redirect authenticated users whose
 * migration_status is `pending`, `in_progress`, or `failed` to
 * /migration/import.
 *
 * Pre-imported users — those whose control-plane row was bulk-copied from
 * beta before the cutover — carry that status. The first thing they see
 * after logging in is the "Copy my data" page; they can't accidentally
 * play on an empty account. Users whose first attempt failed are also
 * redirected so they actually see the retry button.
 *
 * No-op outside import mode, for unauthenticated requests, for users with
 * status `completed`, and for migration / logout paths themselves.
 */
class RequireMigrationOnImport
{
    /** Paths that stay accessible to pending users (matched via str_starts_with). */
    private const ALLOWED_PATHS = [
        '/migration/',
        '/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('migration.mode') !== 'import') {
            return $next($request);
        }

        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! in_array(
            $user->migration_status,
            [MigrationStatus::PENDING, MigrationStatus::IN_PROGRESS, MigrationStatus::FAILED],
            true,
        )) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');

        // Admins must always be able to reach /admin/* — operating the
        // migration itself depends on it, even when their own user row is
        // still in pending/in_progress/failed state.
        if ($user->is_admin && str_starts_with($path, '/admin')) {
            return $next($request);
        }

        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return $next($request);
            }
        }

        return redirect()->route('migration.import.show');
    }
}

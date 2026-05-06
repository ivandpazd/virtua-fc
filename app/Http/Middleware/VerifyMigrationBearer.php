<?php

namespace App\Http\Middleware;

use App\Modules\Migration\Exceptions\InvalidHandoffToken;
use App\Modules\Migration\Services\SignedHandoff;
use App\Modules\Migration\TokenPurpose;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a migration server-to-server bearer token on inbound requests to
 * the export-side API.
 *
 * Usage:
 *
 *   Route::middleware('migration.s2s:s2s_export')->...
 *
 * The verified user_id is attached to the request as `migration_user_id` for
 * downstream actions.
 */
class VerifyMigrationBearer
{
    public function __construct(
        private readonly SignedHandoff $handoff,
    ) {
    }

    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $token = $this->extractBearer($request);
        if ($token === null) {
            abort(401, 'Missing bearer token.');
        }

        try {
            $verified = $this->handoff->verify(TokenPurpose::from($purpose), $token);
        } catch (InvalidHandoffToken $e) {
            abort(401, $e->getMessage());
        } catch (\ValueError) {
            abort(500, 'Invalid migration purpose configured on this route.');
        }

        $request->attributes->set('migration_user_id', $verified->userId);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header === null || ! is_string($header)) {
            return null;
        }

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}

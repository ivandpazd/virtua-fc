<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the migration routes by `config('migration.mode')`. Responds 404 in
 * any other mode so the routes are effectively invisible on a deployment that
 * isn't running the corresponding side of the migration.
 *
 * Usage in routes/web.php:
 *
 *   Route::middleware('migration.mode:export')->group(function () { ... });
 */
class RequireMigrationMode
{
    public function handle(Request $request, Closure $next, string $expectedMode): Response
    {
        if (config('migration.mode') !== $expectedMode) {
            abort(404);
        }

        return $next($request);
    }
}

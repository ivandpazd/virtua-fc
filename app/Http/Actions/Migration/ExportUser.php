<?php

namespace App\Http\Actions\Migration;

use App\Modules\Migration\Services\UserExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Export side, server-to-server endpoint. Returns the JSON envelope for the
 * user identified by the verified bearer token.
 *
 * The middleware (migration.s2s:s2s_export) guarantees the token is valid and
 * has set `migration_user_id` on the request.
 */
class ExportUser
{
    public function __construct(
        private readonly UserExporter $exporter,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('migration_user_id');

        $payload = $this->exporter->export($userId);

        return response()->json($payload);
    }
}

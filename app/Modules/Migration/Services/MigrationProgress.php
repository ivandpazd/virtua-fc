<?php

namespace App\Modules\Migration\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Lightweight progress tracker for a user's migration import job. Backed by
 * the cache (Redis in production), keyed by user id.
 *
 * The import side polls this from the browser to drive the progress bar.
 */
class MigrationProgress
{
    private const TTL_SECONDS = 1800; // 30 minutes — outlives any reasonable import

    public static function set(int $userId, int $percent, string $step, array $extra = []): void
    {
        Cache::put(
            self::cacheKey($userId),
            [
                'percent' => max(0, min(100, $percent)),
                'step' => $step,
                'extra' => $extra,
                'at' => now()->toAtomString(),
            ],
            self::TTL_SECONDS,
        );
    }

    /** @return array{percent: int, step: string, extra: array, at: string}|null */
    public static function get(int $userId): ?array
    {
        return Cache::get(self::cacheKey($userId));
    }

    public static function clear(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }

    private static function cacheKey(int $userId): string
    {
        return "migration:progress:{$userId}";
    }
}

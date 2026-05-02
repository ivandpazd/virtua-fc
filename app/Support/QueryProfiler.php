<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Captures wall-clock time, DB time (sum of per-query time reported by PDO),
 * PHP/round-trip time (the gap), query count, and top query patterns for a
 * scope of work. Used to diagnose where queue jobs spend their time.
 *
 * Toggle via APP_QUERY_PROFILE_JOBS=true (config('app.query_profile_jobs')).
 * When disabled, methods are near no-ops: no query log is collected.
 */
class QueryProfiler
{
    private float $startedAt;

    private bool $enabled;

    public static function enabled(): bool
    {
        return (bool) config('app.query_profile_jobs', false);
    }

    public static function start(): self
    {
        $instance = new self();
        $instance->enabled = self::enabled();
        $instance->startedAt = microtime(true);

        if ($instance->enabled) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }

        return $instance;
    }

    /**
     * Capture stats for the current scope and reset the query log so the next
     * scope starts clean. Returns a structured array suitable for Log::info().
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $wallMs = (int) round((microtime(true) - $this->startedAt) * 1000);

        if (! $this->enabled) {
            return ['wall_ms' => $wallMs, 'profiled' => false];
        }

        $log = DB::getQueryLog();
        $count = count($log);
        $totalDbMs = 0.0;
        $patterns = [];

        foreach ($log as $entry) {
            $time = (float) ($entry['time'] ?? 0);
            $totalDbMs += $time;
            $key = self::normalize($entry['query'] ?? '');

            if (! isset($patterns[$key])) {
                $patterns[$key] = ['count' => 0, 'total_ms' => 0.0, 'sample' => $key];
            }
            $patterns[$key]['count']++;
            $patterns[$key]['total_ms'] += $time;
        }

        // Reset for the next scope; keeps memory bounded over long jobs.
        DB::flushQueryLog();

        $byTime = $patterns;
        uasort($byTime, fn ($a, $b) => $b['total_ms'] <=> $a['total_ms']);

        $byCount = $patterns;
        uasort($byCount, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'profiled' => true,
            'wall_ms' => $wallMs,
            'db_ms' => (int) round($totalDbMs),
            'php_ms' => max(0, $wallMs - (int) round($totalDbMs)),
            'queries' => $count,
            'avg_query_ms' => $count ? round($totalDbMs / $count, 2) : 0,
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'top_by_time' => self::formatTop($byTime, 5),
            'top_by_count' => self::formatTop($byCount, 5),
        ];
    }

    /**
     * @param  array<string, array{count:int,total_ms:float,sample:string}>  $patterns
     * @return array<int, array{sql:string,count:int,total_ms:float}>
     */
    private static function formatTop(array $patterns, int $limit): array
    {
        $out = [];
        foreach (array_slice($patterns, 0, $limit, true) as $p) {
            $out[] = [
                'sql' => self::truncate($p['sample']),
                'count' => $p['count'],
                'total_ms' => round($p['total_ms'], 1),
            ];
        }

        return $out;
    }

    /**
     * Group similar queries: collapse whitespace and `IN (?, ?, ...)` lists
     * so per-row queries with different binding counts cluster together.
     */
    private static function normalize(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'in (...)', $sql) ?? $sql;

        return $sql;
    }

    private static function truncate(string $sql, int $max = 240): string
    {
        return strlen($sql) > $max ? substr($sql, 0, $max).'…' : $sql;
    }
}

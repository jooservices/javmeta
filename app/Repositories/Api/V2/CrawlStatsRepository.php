<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Hourly crawl statistics, the queue totals and the latest runs, as the Command Center reads them. */
final class CrawlStatsRepository
{
    /** @return array<string, int> */
    public function totalsSince(CarbonInterface $since): array
    {
        $row = DB::table('crawl_stats_hourly')
            ->where('hour_at', '>', $since)
            ->selectRaw('COALESCE(SUM(attempts), 0) AS attempts, COALESCE(SUM(ok), 0) AS ok, COALESCE(SUM(blocked), 0) AS blocked, COALESCE(SUM(timeout), 0) AS timeout, COALESCE(SUM(other_error), 0) AS errors, COALESCE(SUM(movies_new), 0) AS movies_new, COALESCE(SUM(performers_new), 0) AS performers_new')
            ->first();

        return array_map('intval', (array) $row);
    }

    /** @return list<stdClass> */
    public function hourlyOverview(CarbonInterface $since): array
    {
        return DB::table('crawl_stats_hourly')
            ->where('hour_at', '>', $since)
            ->groupBy('hour_at')
            ->orderBy('hour_at')
            ->selectRaw('hour_at, SUM(attempts) AS attempts, SUM(ok) AS ok, SUM(blocked) AS blocked, SUM(timeout) AS timeout, SUM(other_error) AS errors, SUM(movies_new) AS movies_new')
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    public function hourlyPerformance(CarbonInterface $since): array
    {
        return DB::table('crawl_stats_hourly')
            ->where('hour_at', '>', $since)
            ->where('source_slug', '<>', '__global__')
            ->groupBy('hour_at')
            ->orderBy('hour_at')
            ->selectRaw('hour_at, SUM(attempts) AS attempts, SUM(ok) AS ok, SUM(blocked) AS blocked, SUM(timeout) AS timeout, SUM(other_error) AS err, SUM(skipped) AS skipped, AVG(avg_duration_ms) AS avg_ms, MAX(p90_duration_ms) AS p90, AVG(queue_ready_avg) AS queue_ready, AVG(postgres_connections_avg) AS pg')
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    public function sourceTotalsSince(CarbonInterface $since): array
    {
        return DB::table('crawl_stats_hourly')
            ->where('hour_at', '>', $since)
            ->groupBy('source_slug')
            ->selectRaw('source_slug, SUM(attempts) AS attempts, SUM(ok) AS ok, SUM(blocked) AS blocked, SUM(timeout) AS timeout, SUM(other_error) AS errors, SUM(movies_new) AS movies_new, SUM(movies_updated) AS movies_updated')
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    public function sourceHistory(string $slug, CarbonInterface $since): array
    {
        return DB::table('crawl_stats_hourly')
            ->where('source_slug', $slug)
            ->where('hour_at', '>', $since)
            ->groupBy('hour_at')
            ->orderBy('hour_at')
            ->selectRaw('hour_at, SUM(attempts) AS attempts, SUM(ok) AS ok, SUM(blocked) AS blocked, SUM(movies_new) AS movies_new, AVG(avg_duration_ms) AS avg_ms')
            ->get()
            ->all();
    }

    /** @return array{pending: int, claimed: int, failed: int} */
    public function queueTotals(): array
    {
        $row = DB::table('crawl_queue')
            ->whereIn('status', ['pending', 'claimed', 'failed'])
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending, COALESCE(SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END), 0) AS claimed, COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed")
            ->first();

        return ['pending' => (int) ($row->pending ?? 0), 'claimed' => (int) ($row->claimed ?? 0), 'failed' => (int) ($row->failed ?? 0)];
    }

    /** @return list<stdClass> */
    public function claimedLanes(): array
    {
        return DB::table('crawl_queue')
            ->where('status', 'claimed')
            ->groupBy('claimed_lane')
            ->selectRaw('claimed_lane, COUNT(*) AS n')
            ->get()
            ->all();
    }

    /** @return array{last: string|null, n10: int, n60: int} */
    public function runActivity(CarbonInterface $now): array
    {
        $row = DB::table('crawl_runs')
            ->where('started_at', '>', $now->copy()->subHours(2))
            ->selectRaw('MAX(started_at) AS last, COALESCE(SUM(CASE WHEN started_at > ? THEN 1 ELSE 0 END), 0) AS n10, COALESCE(SUM(CASE WHEN started_at > ? THEN 1 ELSE 0 END), 0) AS n60', [$now->copy()->subMinutes(10), $now->copy()->subMinutes(60)])
            ->first();

        return ['last' => $row->last ?? null, 'n10' => (int) ($row->n10 ?? 0), 'n60' => (int) ($row->n60 ?? 0)];
    }
}

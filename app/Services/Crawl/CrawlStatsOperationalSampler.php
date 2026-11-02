<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CrawlStatsOperationalSampler
{
    public function sample(): void
    {
        if (! (bool) config('javmeta_stats.enabled', true)
            || ! Schema::hasTable('crawl_stats_hourly')
            || ! Schema::hasTable('crawl_queue')) {
            return;
        }

        $now = now()->utc();
        $hour = $now->copy()->startOfHour();
        $key = [
            'hour_at' => $hour,
            'source_slug' => CrawlStatsRollup::GLOBAL_SOURCE,
            'lane' => CrawlStatsRollup::GLOBAL_LANE,
            'method' => CrawlStatsRollup::GLOBAL_METHOD,
        ];

        $queue = DB::table('crawl_queue')->selectRaw(
            'SUM(CASE WHEN status = ? AND (next_attempt_at IS NULL OR next_attempt_at <= ?) THEN 1 ELSE 0 END) AS ready, '
            . 'SUM(CASE WHEN status = ? AND next_attempt_at > ? THEN 1 ELSE 0 END) AS delayed, '
            . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS reserved',
            ['pending', $now, 'pending', $now, 'claimed'],
        )->first();

        $playwrightRatio = null;
        $flaresolverrRatio = null;
        if (Schema::hasTable('crawl_fetch_attempts')) {
            $durations = DB::table('crawl_fetch_attempts')
                ->where('created_at', '>=', $now->copy()->subMinute())
                ->selectRaw(
                    "SUM(CASE WHEN lower(method) IN ('playwright', 'playwright_stealth', 'chrome_stealth') AND outcome <> 'skipped' THEN duration_ms ELSE 0 END) AS playwright_ms, "
                    . "SUM(CASE WHEN lower(method) = 'flaresolverr' AND outcome <> 'skipped' THEN duration_ms ELSE 0 END) AS flaresolverr_ms",
                )->first();
            $slots = max(1, (int) config('javmeta_queues.browser_inflight', 8));
            $playwrightRatio = min(1.0, max(0.0, (int) ($durations->playwright_ms ?? 0) / ($slots * 60_000)));
            $flaresolverrRatio = min(1.0, max(0.0, (int) ($durations->flaresolverr_ms ?? 0) / ($slots * 60_000)));
        }

        $values = array_merge($key, [
            'queue_ready_avg' => (float) ($queue->ready ?? 0),
            'queue_delayed_avg' => (float) ($queue->delayed ?? 0),
            'queue_reserved_avg' => (float) ($queue->reserved ?? 0),
            'playwright_busy_ratio_avg' => $playwrightRatio,
            'flaresolverr_busy_ratio_avg' => $flaresolverrRatio,
            'queue_samples' => 1,
            'playwright_samples' => $playwrightRatio === null ? 0 : 1,
            'flaresolverr_samples' => $flaresolverrRatio === null ? 0 : 1,
            'postgres_connections_avg' => null,
            'postgres_samples' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $updates = [
            'queue_ready_avg' => DB::raw($this->averageExpression('queue_ready_avg', 'queue_samples')),
            'queue_delayed_avg' => DB::raw($this->averageExpression('queue_delayed_avg', 'queue_samples')),
            'queue_reserved_avg' => DB::raw($this->averageExpression('queue_reserved_avg', 'queue_samples')),
            'queue_samples' => DB::raw('crawl_stats_hourly.queue_samples + 1'),
            'playwright_busy_ratio_avg' => DB::raw($this->averageExpression('playwright_busy_ratio_avg', 'playwright_samples')),
            'flaresolverr_busy_ratio_avg' => DB::raw($this->averageExpression('flaresolverr_busy_ratio_avg', 'flaresolverr_samples')),
            'playwright_samples' => DB::raw($this->sampleCountExpression('playwright_busy_ratio_avg', 'playwright_samples')),
            'flaresolverr_samples' => DB::raw($this->sampleCountExpression('flaresolverr_busy_ratio_avg', 'flaresolverr_samples')),
            'updated_at' => DB::raw('excluded.updated_at'),
        ];
        if (DB::connection()->getDriverName() === 'pgsql') {
            $connections = DB::selectOne("SELECT COUNT(*)::int AS used FROM pg_stat_activity WHERE backend_type = 'client backend'");
            $values['postgres_connections_avg'] = (float) ($connections->used ?? 0);
            $values['postgres_samples'] = 1;
            $updates['postgres_connections_avg'] = DB::raw($this->averageExpression('postgres_connections_avg', 'postgres_samples'));
            $updates['postgres_samples'] = DB::raw('crawl_stats_hourly.postgres_samples + 1');
        }

        DB::table('crawl_stats_hourly')->upsert([$values], ['hour_at', 'source_slug', 'lane', 'method'], $updates);
    }

    private function averageExpression(string $average, string $samples): string
    {
        return sprintf(
            'CASE WHEN excluded.%1$s IS NULL THEN crawl_stats_hourly.%1$s WHEN crawl_stats_hourly.%1$s IS NULL OR crawl_stats_hourly.%2$s = 0 THEN excluded.%1$s ELSE crawl_stats_hourly.%1$s + (1.0 * (excluded.%1$s - crawl_stats_hourly.%1$s) / (crawl_stats_hourly.%2$s + 1)) END',
            $average,
            $samples,
        );
    }

    private function sampleCountExpression(string $average, string $samples): string
    {
        return sprintf(
            'CASE WHEN excluded.%1$s IS NULL THEN crawl_stats_hourly.%2$s ELSE crawl_stats_hourly.%2$s + 1 END',
            $average,
            $samples,
        );
    }
}

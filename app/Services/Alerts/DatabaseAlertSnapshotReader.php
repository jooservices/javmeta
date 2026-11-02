<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Health\CrawlDispatchHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseAlertSnapshotReader implements AlertSnapshotReader
{
    public function __construct(private readonly CrawlDispatchHeartbeat $dispatchHeartbeat) {}

    /** @return array{attempts_by_source: array<string, array{attempts: int, successes: int, blocked: int}>, enabled_sources: list<string>, hot_sources: list<string>, due_by_source: array<string, int>, postgres_connections: int|null, dispatch_age_seconds: int|null, has_due_work: bool} */
    public function snapshot(CarbonImmutable $cutoff): array
    {
        $attempts = $this->attemptsBySource($cutoff);
        $dueBySource = $this->dueBySource();
        $enabledSources = Source::query()->where('enabled', true)->orderBy('slug')->pluck('slug')->map('strval')->all();
        $hotSources = $this->hotSources($cutoff, array_keys($attempts));

        return [
            'attempts_by_source' => $attempts,
            'enabled_sources' => $enabledSources,
            'hot_sources' => $hotSources,
            'due_by_source' => $dueBySource,
            'postgres_connections' => $this->postgresConnections(),
            'dispatch_age_seconds' => $this->dispatchHeartbeat->ageSeconds(),
            'has_due_work' => array_sum($dueBySource) > 0,
        ];
    }

    /** @return array<string, array{attempts: int, successes: int, blocked: int}> */
    private function attemptsBySource(CarbonImmutable $cutoff): array
    {
        $rows = DB::table('crawl_fetch_attempts')
            ->where('started_at', '>=', $cutoff)
            ->where('outcome', '<>', 'skipped')
            ->selectRaw('source_slug, COUNT(*) AS attempts, SUM(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS successes, SUM(CASE WHEN http_status IN (403, 429) OR NULLIF(cf_mitigated, ?) IS NOT NULL THEN 1 ELSE 0 END) AS blocked', [''])
            ->groupBy('source_slug')
            ->get();
        $attempts = [];

        foreach ($rows as $row) {
            $source = (string) $row->source_slug;
            $attempts[$source] = [
                'attempts' => (int) $row->attempts,
                'successes' => (int) $row->successes,
                'blocked' => (int) $row->blocked,
            ];
        }

        return $attempts;
    }

    /** @return array<string, int> */
    private function dueBySource(): array
    {
        $rows = DB::table('crawl_queue')
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->where(static function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->selectRaw('source_slug, COUNT(*) AS due_count')
            ->groupBy('source_slug')
            ->get();
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->source_slug] = (int) $row->due_count;
        }

        return $counts;
    }

    /** @param list<string> $sourcesWithAttempts
     *  @return list<string>
     */
    private function hotSources(CarbonImmutable $cutoff, array $sourcesWithAttempts): array
    {
        $query = Source::query()->where('enabled', true)->where(static function ($query) use ($cutoff, $sourcesWithAttempts): void {
            $query->where('last_success_at', '>=', $cutoff);
            if ($sourcesWithAttempts !== []) {
                $query->orWhereIn('slug', $sourcesWithAttempts);
            }
        });

        return $query->orderBy('slug')->pluck('slug')->map('strval')->all();
    }

    private function postgresConnections(): ?int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }

        $row = DB::selectOne("SELECT COUNT(*)::int AS used FROM pg_stat_activity WHERE backend_type = 'client backend'");

        return max(0, (int) ($row->used ?? 0));
    }
}

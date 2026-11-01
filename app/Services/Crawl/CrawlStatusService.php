<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\Source;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate crawl pipeline status for monitoring (MCP `crawl_status`).
 */
final class CrawlStatusService
{
    public function __construct(private readonly WorkerHeartbeat $heartbeat) {}

    /**
     * @return array{movies: int, performers: int}
     */
    public function counts(): array
    {
        return [
            'movies' => (int) Movie::query()->count(),
            'performers' => (int) Performer::query()->whereNull('canonical_performer_id')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $staleSeconds = (int) config('javmeta_sources.defaults.queue.stale_claim_timeout_seconds', 900);
        $now = now();
        $claimCutoff = now()->subSeconds($staleSeconds);

        return [
            'checked_at' => $now->toIso8601String(),
            'counts' => $this->counts(),
            'ingest' => [
                'movies_1h' => $this->newSince(Movie::class, 'first_seen_at', 3600),
                'movies_24h' => $this->newSince(Movie::class, 'first_seen_at', 86400),
                'performers_1h' => $this->newSince(Performer::class, 'first_seen_at', 3600),
                'performers_24h' => $this->newSince(Performer::class, 'first_seen_at', 86400),
            ],
            'queue' => $this->queueStatus($claimCutoff),
            'sources' => $this->sourceStatus(),
            'workers' => $this->workerStatus($claimCutoff),
            'runs_24h' => $this->runsStatus(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueStatus(DateTimeInterface $claimCutoff): array
    {
        $queueByStatus = CrawlQueue::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn(mixed $value): int => (int) $value)
            ->all();

        $pendingKinds = DB::table('crawl_queue')
            ->select('kind', 'status', DB::raw('count(*) as total'))
            ->whereIn('status', [CrawlQueue::STATUS_PENDING, CrawlQueue::STATUS_CLAIMED, CrawlQueue::STATUS_FAILED])
            ->groupBy('kind', 'status')
            ->orderBy('kind')
            ->get()
            ->map(fn($row): array => [
                'kind' => $row->kind,
                'status' => $row->status,
                'count' => (int) $row->total,
            ])
            ->all();

        $oldestPending = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->orderBy('next_attempt_at')
            ->value('next_attempt_at');

        return [
            'by_status' => $queueByStatus,
            'by_kind_status' => $pendingKinds,
            'oldest_pending_at' => $oldestPending instanceof DateTimeInterface
                ? $oldestPending->format(DATE_ATOM)
                : null,
            'stuck_claimed' => CrawlQueue::query()
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where('claimed_at', '<', $claimCutoff)
                ->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceStatus(): array
    {
        $rows = [];
        $lanes = app(CrawlLaneService::class);
        $browser = $lanes->browserSources();
        foreach (Source::query()->orderBy('priority')->orderBy('slug')->get() as $source) {
            $rows[] = [
                'slug' => $source->slug,
                'name' => $source->name,
                'enabled' => (bool) $source->enabled,
                'lane' => config('javmeta_queues.lanes_enabled', true) && in_array((string) $source->slug, $browser, true) ? 'browser' : 'direct',
                // Deprecated: crawlerx owns fetch health; kept so MCP crawl_status keys stay stable.
                'circuit_state' => 'closed',
                'consecutive_failures' => 0,
                'gap_seconds_current' => (float) $source->gap_seconds_current,
                'gap_seconds_min' => (float) $source->gap_seconds_min,
                'gap_seconds_max' => (float) $source->gap_seconds_max,
                'last_success_at' => $this->dateTime($source->getAttribute('last_success_at')),
                'last_error_at' => $this->dateTime($source->getAttribute('last_error_at')),
                'last_error' => $source->last_error,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workerStatus(DateTimeInterface $claimCutoff): array
    {
        $activeClaims = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->where('claimed_at', '>=', $claimCutoff)
            ->select('locked_by', DB::raw('count(*) as total'))
            ->groupBy('locked_by')
            ->pluck('total', 'locked_by')
            ->map(static fn(mixed $value): int => (int) $value)
            ->all();

        $workers = [];
        foreach ($this->heartbeat->instances() as $row) {
            $instance = $row['instance'];
            $workers[] = [
                'instance' => $instance,
                'last_heartbeat_at' => $row['last_heartbeat_at'],
                'stale' => $row['stale'],
                'active_claims' => $activeClaims[$instance] ?? 0,
            ];
        }

        return $workers;
    }

    /**
     * @return array<string, int>
     */
    private function runsStatus(): array
    {
        // Aggregate in PostgreSQL instead of hydrating every run in the busy 24-hour window.
        $runs = CrawlRun::query()
            ->where('started_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) AS runs, SUM(pages_fetched) AS pages_fetched, SUM(movies_new) AS movies_new, SUM(movies_updated) AS movies_updated, SUM(failures) AS failures')
            ->first();

        return [
            'runs' => (int) $runs?->getAttribute('runs'),
            'pages_fetched' => (int) $runs?->getAttribute('pages_fetched'),
            'movies_new' => (int) $runs?->getAttribute('movies_new'),
            'movies_updated' => (int) $runs?->getAttribute('movies_updated'),
            'failures' => (int) $runs?->getAttribute('failures'),
        ];
    }

    private function newSince(string $model, string $column, int $seconds): int
    {
        return (int) $model::query()->where($column, '>=', now()->subSeconds($seconds))->count();
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}

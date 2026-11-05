<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\DashboardSnapshot;
use App\Repositories\CrawlEventRepository;
use App\Repositories\CrawlQueueRepository;
use App\Repositories\CrawlRunRepository;
use App\Repositories\FailedJobRepository;
use App\Repositories\MovieDashboardRepository;
use App\Repositories\PerformerRepository;
use App\Repositories\SourceRepository;
use Illuminate\Support\Carbon;

/** Builds the operations dashboard snapshot from crawl and catalog repositories. */
final class OperationsDashboardService
{
    public function __construct(
        private readonly CrawlQueueRepository $crawlQueue,
        private readonly CrawlRunRepository $crawlRuns,
        private readonly SourceRepository $sources,
        private readonly CrawlEventRepository $crawlEvents,
        private readonly MovieDashboardRepository $movies,
        private readonly PerformerRepository $performers,
        private readonly FailedJobRepository $failedJobs,
    ) {}

    public function snapshot(): DashboardSnapshot
    {
        $since = now()->subHour();
        $queueTotals = ['pending' => 0, 'claimed' => 0, 'done' => 0, 'failed' => 0];
        $queueBySource = [];
        foreach ($this->crawlQueue->totalsBySourceAndStatus() as $row) {
            $status = (string) $row->status;
            $slug = (string) $row->source_slug;
            $count = (int) $row->total;
            $queueTotals[$status] = ($queueTotals[$status] ?? 0) + $count;
            $queueBySource[$slug][$status] = $count;
        }

        $runs = $this->crawlRuns->summarySince($since);
        $sources = $this->sources->enabledForDashboard()->map(static function (object $source) use ($queueBySource): array {
            $lastSuccess = $source->last_success_at === null ? null : Carbon::parse($source->last_success_at);
            $age = $lastSuccess === null ? null : (int) $lastSuccess->diffInSeconds(now());
            // Attention = never succeeded, nothing succeeded for 30 minutes, or the latest error is newer than the latest success.
            $failingNow = $source->last_error_at !== null && ($lastSuccess === null || Carbon::parse($source->last_error_at)->greaterThan($lastSuccess));
            $status = $age === null || $age > 1800 || $failingNow ? 'attention' : 'healthy';

            return [
                'slug' => (string) $source->slug,
                'name' => (string) $source->name,
                'status' => $status,
                'last_success_at' => $lastSuccess?->toIso8601String(),
                'last_success_age_seconds' => $age,
                'last_error_at' => $source->last_error_at,
                'last_error' => mb_strimwidth((string) ($source->last_error ?? ''), 0, 220, '…'),
                'queue' => $queueBySource[(string) $source->slug] ?? [],
            ];
        })->values()->all();

        $issues = $this->crawlQueue->recentFailures(12)->map(static fn(object $row): array => [
            'source' => (string) $row->source_slug,
            'kind' => (string) $row->kind,
            'code' => (string) ($row->error_code ?? 'unknown'),
            'attempts' => (int) $row->attempts,
            'detail' => mb_strimwidth((string) ($row->last_error ?? ''), 0, 220, '…'),
        ])->values()->all();

        $events = $this->crawlEvents->recent(12)->map(static fn(object $row): array => [
            'source' => (string) $row->source_slug,
            'kind' => (string) $row->kind,
            'at' => $row->created_at,
        ])->values()->all();

        $uiLoginRequired = (bool) config('javmeta.ui_login_required', true);

        return new DashboardSnapshot(
            asOf: now()->toIso8601String(),
            uiLoginRequired: $uiLoginRequired,
            uiLoginState: $uiLoginRequired ? 'Yêu cầu đăng nhập' : 'Đăng nhập giao diện đã tắt (chế độ khách)',
            totals: [
                'movies' => $this->movies->countAll(),
                'performers' => $this->performers->count(),
                'movies_first_seen_1h' => $this->movies->countFirstSeenSince($since),
                'failed_jobs' => $this->failedJobs->count(),
            ],
            queue: $queueTotals,
            hour: [
                'runs' => (int) ($runs->total ?? 0),
                'pages' => (int) ($runs->pages ?? 0),
                'movies_new' => (int) ($runs->movies_new ?? 0),
                'movies_updated' => (int) ($runs->movies_updated ?? 0),
                'failures' => (int) ($runs->failures ?? 0),
            ],
            sources: $sources,
            issues: $issues,
            events: $events,
        );
    }
}

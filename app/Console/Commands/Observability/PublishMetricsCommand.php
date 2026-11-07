<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Repositories\ObservabilityServiceRepository;
use App\Models\ApiUsageLog;
use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\ReviewFlag;
use App\Models\Source;
use App\Observability\OpenObserveClient;
use App\Observability\MetricStreams;
use App\Observability\NotificationMetrics;
use App\Observability\NormalizationRejectionMetrics;
use App\Services\Alerts\AlertMetrics;
use App\Services\Archive\MongoArchiveWriteHealth;
use App\Services\Crawl\CrawlerxFetchMethodPlan;
use App\Services\Crawl\CrawlStatsOperationalSampler;
use App\Services\Crawl\FeedPoolDiagnostics;
use App\Services\Crawl\SourceGapPolicy;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawl\SourceCircuitBreaker;
use App\Services\Health\CrawlDispatchHeartbeat;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchRequestHealth;
use App\Services\Health\SourceScheduleFreshness;
use App\Services\Observability\NodeEgressProbe;
use App\Services\Workers\HorizonLayoutSnapshot;
use App\Services\Workers\HorizonWorkerLayout;
use App\Services\Workers\PostgresConnectionBudget;
use App\Services\Report\NodeSampleAggregator;
use App\Services\Report\NodeSampleStore;
use App\Services\Report\ReportMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;
use Throwable;

final class PublishMetricsCommand extends Command
{
    protected $signature = 'obs:publish-metrics';

    protected $aliases = ['javmeta:obs-publish-metrics'];

    protected $description = 'Publish javmeta ops and quality aggregate metrics to OpenObserve.';

    /**
     * Publish current operational, freshness, dependency, and capacity metrics.
     */
    public function handle(
        OpenObserveClient $client,
        MongoArchiveWriteHealth $archiveWrites,
        ElasticsearchHttpClient $elasticsearch,
        ElasticsearchRequestHealth $elasticsearchHealth,
        SourceScheduleFreshness $sourceFreshness,
        CrawlDispatchHeartbeat $dispatchHeartbeat,
        CrawlerxFetchMethodPlan $fetchMethodPlan,
        FeedPoolDiagnostics $feedPoolDiagnostics,
        SourceGapPolicy $gapPolicy,
        SourceThrottle $throttle,
        AlertMetrics $alertMetrics,
        CrawlStatsOperationalSampler $statsSampler,
        NodeEgressProbe $egressProbe,
        NotificationMetrics $notificationMetrics,
        NodeSampleStore $nodeSamples,
        NodeSampleAggregator $sampleAggregator,
        ReportMetrics $reportMetrics,
        NormalizationRejectionMetrics $normalizationMetrics,
        HorizonWorkerLayout $workerLayout,
        PostgresConnectionBudget $connectionBudget,
    ): int {
        try {
            $statsSampler->sample();
        } catch (Throwable $exception) {
            Log::warning('Crawl stats sampling failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);
        }

        if (! $client->enabled()) {
            $this->components->info('OpenObserve disabled; skipping metrics publish.');

            return self::SUCCESS;
        }

        $points = array_merge(
            $this->sourceMetrics($sourceFreshness, $gapPolicy, $throttle),
            $this->queueMetrics(),
            $feedPoolDiagnostics->metricPointsForMinute(CarbonImmutable::now()->startOfMinute()->subMinute()),
            $this->crawlDispatchMetrics($dispatchHeartbeat),
            $this->crawlEventMetrics(),
            $this->fetchMethodMetrics($fetchMethodPlan),
            $this->fetchEgressMetrics(),
            $this->nodeEgressMetrics($egressProbe),
            $this->browserEgressMetrics($egressProbe),
            $this->apiMetrics(),
            $this->qualityMetrics(),
            $this->crawlRunMetrics(),
            $this->catalogMetrics(),
            $this->failedJobMetrics(),
            $this->postgresCapacityMetrics(),
            $this->postgresConnectionBudgetMetrics($workerLayout, $connectionBudget),
            $this->alertMetricPoints($alertMetrics),
            $this->horizonWorkerMetrics(),
            $this->nodeMetrics($nodeSamples, $sampleAggregator),
            $reportMetrics->points(),
            [[
                'name' => MetricStreams::NAMES['mongo_archive_write_failures'],
                'value' => $archiveWrites->failureCount(),
            ]],
            $this->dependencyProbeMetrics($elasticsearch),
            $notificationMetrics->points(),
            $normalizationMetrics->points(),
            [[
                'name' => MetricStreams::NAMES['elasticsearch_request_failures'],
                'value' => $elasticsearchHealth->failureCount(),
            ]],
        );

        $client->ingestMetrics($points);
        $this->components->info('Published ' . count($points) . ' metric points.');

        return self::SUCCESS;
    }

    /** @return list<array{name: string, value: int, labels?: array<string, string>}> */
    private function alertMetricPoints(AlertMetrics $alertMetrics): array
    {
        try {
            return $alertMetrics->points();
        } catch (Throwable $exception) {
            Log::warning('javmeta.alert_metrics_failed', ['exception' => $exception::class]);

            return [];
        }
    }

    /**
     * @param  SourceScheduleFreshness  $sourceFreshness  Schedule rules for each enabled source.
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function sourceMetrics(SourceScheduleFreshness $sourceFreshness, SourceGapPolicy $gapPolicy, SourceThrottle $throttle): array
    {
        app(SourceCircuitBreaker::class)->pausedSourceSlugs();
        $points = [];
        $defaultStaleSeconds = (int) config('javmeta_alerts.watchdog.source_stale_seconds', 86400);

        foreach (app(ObservabilityServiceRepository::class)->modelQuery(Source::class)->get() as $source) {
            $gapDetails = $gapPolicy->describe((string) $source->getKey(), $source);
            $labels = [
                'source_slug' => (string) $source->getKey(),
                'gap_source' => $gapDetails['overridden'] ? 'override' : 'definition',
            ];
            $points[] = [
                'name' => MetricStreams::NAMES['source_gap_seconds'],
                'value' => $throttle->currentGap($source),
                'labels' => $labels,
            ];
            $points[] = [
                'name' => MetricStreams::NAMES['source_gap_effective_seconds'],
                'value' => $gapDetails['effective']['default'],
                'labels' => $labels,
            ];
            $age = $source->last_success_at !== null
                ? max(0, now()->diffInSeconds($source->last_success_at))
                : -1;
            $points[] = [
                'name' => MetricStreams::NAMES['source_last_success_age_seconds'],
                'value' => $age,
                'labels' => $labels,
            ];
            $points[] = [
                'name' => MetricStreams::NAMES['source_past_due'],
                'value' => $source->enabled && $sourceFreshness->isPastDue($source->last_success_at, (string) $source->slug, $defaultStaleSeconds) ? 1 : 0,
                'labels' => $labels,
            ];
            $points[] = [
                'name' => MetricStreams::NAMES['source_circuit_open'],
                'value' => $source->circuit_state === 'open' ? 1 : 0,
                'labels' => $labels,
            ];
            $points[] = [
                'name' => MetricStreams::NAMES['source_consecutive_blocks'],
                'value' => (int) $source->consecutive_failures,
                'labels' => $labels,
            ];
        }

        return $points;
    }

    /** @return list<array{name: string, value: int, labels: array{queue: string, node: string}}> */
    private function horizonWorkerMetrics(): array
    {
        try {
            $redis = Redis::connection(config('horizon.use', 'default'));
            $snapshots = [];
            foreach ((array) $redis->smembers(HorizonLayoutSnapshot::NODE_REGISTRY_KEY) as $node) {
                if (! is_string($node) || $node === '') {
                    continue;
                }
                try {
                    $payload = json_decode((string) $redis->get(HorizonLayoutSnapshot::key($node)), true);
                    if (is_array($payload['snapshot'] ?? null)) {
                        $snapshots[] = $payload['snapshot'];
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            return HorizonLayoutSnapshot::metricPoints($snapshots);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{name: string, value: float|int, labels: array<string, string>}> */
    private function nodeMetrics(NodeSampleStore $samples, NodeSampleAggregator $aggregator): array
    {
        $now = CarbonImmutable::now();
        $nodes = $aggregator->aggregate(
            $samples->all(),
            $now->subMinutes(3),
            $now,
            max(60, (int) config('javmeta_report.sample_max_age_seconds', 180)),
        );
        $points = [];
        foreach ($nodes as $node => $sample) {
            foreach ([
                'cpu_latest' => 'node_cpu_percent',
                'mem_available_bytes' => 'node_mem_available_bytes',
                'disk_used_percent' => 'node_disk_used_percent',
            ] as $field => $metric) {
                if (is_numeric($sample[$field] ?? null)) {
                    $points[] = [
                        'name' => MetricStreams::NAMES[$metric],
                        'value' => $sample[$field],
                        'labels' => ['node' => $node],
                    ];
                }
            }
            foreach ((array) ($sample['workers']['lanes'] ?? []) as $lane => $counts) {
                foreach (['busy', 'idle', 'total'] as $state) {
                    $points[] = [
                        'name' => MetricStreams::NAMES['workers'],
                        'value' => (int) ($counts[$state] ?? 0),
                        'labels' => ['node' => $node, 'lane' => (string) $lane, 'state' => $state],
                    ];
                }
            }
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function queueMetrics(): array
    {
        $points = [];
        $rows = app(ObservabilityServiceRepository::class)->modelQuery(CrawlQueue::class)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $points[] = [
                'name' => MetricStreams::NAMES['crawl_queue_depth'],
                'value' => (int) $row->getAttribute('c'),
                'labels' => ['status' => (string) $row->status],
            ];
        }

        $oldestClaimed = app(ObservabilityServiceRepository::class)->modelQuery(CrawlQueue::class)
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->whereNotNull('claimed_at')
            ->orderBy('claimed_at')
            ->value('claimed_at');
        $points[] = [
            'name' => MetricStreams::NAMES['crawl_queue_claimed_age_seconds'],
            'value' => $oldestClaimed !== null ? max(0, (int) now()->diffInSeconds($oldestClaimed)) : -1,
        ];

        $avgAttempts = app(ObservabilityServiceRepository::class)->modelQuery(CrawlQueue::class)
            ->whereIn('status', [CrawlQueue::STATUS_PENDING, CrawlQueue::STATUS_RETRY_PENDING, CrawlQueue::STATUS_FAILED])
            ->avg('attempts');
        $points[] = [
            'name' => MetricStreams::NAMES['crawl_queue_avg_attempts'],
            'value' => $avgAttempts !== null ? (float) $avgAttempts : 0.0,
        ];

        $egressLabel = (string) data_get(config('javmeta_queues.retry.fetch_profile', []), 'egress_label', 'default');
        foreach (app(ObservabilityServiceRepository::class)->modelQuery(CrawlQueue::class)->where('status', CrawlQueue::STATUS_RETRY_PENDING)
            ->select('source_slug', 'retry_round', 'retry_egress_label')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('source_slug', 'retry_round', 'retry_egress_label')->get() as $row) {
            $points[] = [
                'name' => MetricStreams::NAMES['retry_pending'],
                'value' => (int) $row->getAttribute('total'),
                'labels' => [
                    'source' => (string) $row->source_slug,
                    'round' => (string) $row->retry_round,
                    'egress' => (string) ($row->retry_egress_label ?: $egressLabel),
                ],
            ];
        }

        foreach (app(ObservabilityServiceRepository::class)->tableQuery('crawl_retry_metrics')->get() as $row) {
            $name = match ((string) $row->outcome) {
                'success' => 'retry_success_total',
                'failed_permanent' => 'retry_failed_permanent_total',
                default => null,
            };
            if ($name !== null) {
                $points[] = [
                    'name' => MetricStreams::NAMES[$name],
                    'value' => (int) $row->value,
                    'labels' => [
                        'source' => (string) $row->source_slug,
                        'round' => (string) $row->round,
                        'egress' => (string) $row->egress_label,
                    ],
                ];
            }
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function crawlDispatchMetrics(CrawlDispatchHeartbeat $dispatchHeartbeat): array
    {
        $age = $dispatchHeartbeat->ageSeconds();
        $hasDueWork = app(ObservabilityServiceRepository::class)->modelQuery(CrawlQueue::class)
            ->whereIn('status', [CrawlQueue::STATUS_PENDING, CrawlQueue::STATUS_RETRY_PENDING])
            ->where(static function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->exists();
        $warningThreshold = max(1, (int) config('javmeta_alerts.watchdog.crawl_dispatch_warning_seconds', 60));
        $criticalThreshold = max(
            $warningThreshold,
            (int) config('javmeta_alerts.watchdog.crawl_dispatch_critical_seconds', 180),
        );

        return [
            [
                'name' => MetricStreams::NAMES['crawl_dispatch_age_seconds'],
                'value' => $age ?? -1,
            ],
            [
                'name' => MetricStreams::NAMES['crawl_dispatch_stale'],
                'value' => $hasDueWork && ($age === null || $age >= $warningThreshold) ? 1 : 0,
                'labels' => ['severity' => 'warning'],
            ],
            [
                'name' => MetricStreams::NAMES['crawl_dispatch_stale'],
                'value' => $hasDueWork && ($age === null || $age >= $criticalThreshold) ? 1 : 0,
                'labels' => ['severity' => 'critical'],
            ],
        ];
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function crawlEventMetrics(): array
    {
        $points = [];
        $since = now()->subMinutes(5);
        $rows = app(ObservabilityServiceRepository::class)->modelQuery(CrawlEvent::class)
            ->where('created_at', '>=', $since)
            ->selectRaw('kind, count(*) as c')
            ->groupBy('kind')
            ->get();

        foreach ($rows as $row) {
            $points[] = [
                'name' => MetricStreams::NAMES['crawl_events_5m'],
                'value' => (int) $row->getAttribute('c'),
                'labels' => ['kind' => (string) $row->kind],
            ];
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function fetchMethodMetrics(CrawlerxFetchMethodPlan $fetchMethodPlan): array
    {
        $points = [];
        $playwrightDurationMs = 0;
        $flaresolverrDurationMs = 0;

        if (Schema::hasTable('crawl_fetch_attempts')) {
            $rows = app(ObservabilityServiceRepository::class)->tableQuery('crawl_fetch_attempts')
                ->where('created_at', '>=', now()->subMinute())
                ->selectRaw('source_slug, method, outcome, COUNT(*) AS attempts, COALESCE(SUM(duration_ms), 0) AS duration_ms')
                ->groupBy('source_slug', 'method', 'outcome')
                ->get();

            foreach ($rows as $row) {
                $source = (string) $row->source_slug;
                $method = (string) $row->method;
                $outcome = (string) $row->outcome;
                $points[] = [
                    'name' => MetricStreams::NAMES['fetch_method_attempts'],
                    'value' => (int) $row->attempts,
                    'labels' => ['source' => $source, 'method' => $method, 'outcome' => $outcome],
                ];

                if (in_array($method, ['playwright', 'playwright_stealth', 'chrome_stealth'], true) && $outcome !== 'skipped') {
                    $playwrightDurationMs += (int) $row->duration_ms;
                }
                if ($method === 'flaresolverr' && $outcome !== 'skipped') {
                    $flaresolverrDurationMs += (int) $row->duration_ms;
                }
            }
        }

        $slots = max(1, (int) config('javmeta_queues.browser_inflight', 8));
        $points[] = [
            'name' => MetricStreams::NAMES['playwright_slot_busy_ratio'],
            'value' => min(1.0, max(0.0, $playwrightDurationMs / ($slots * 60_000))),
        ];
        $points[] = [
            'name' => MetricStreams::NAMES['flaresolverr_slot_busy_ratio'],
            'value' => min(1.0, max(0.0, $flaresolverrDurationMs / ($slots * 60_000))),
        ];

        foreach (app(ObservabilityServiceRepository::class)->modelQuery(Source::class)->get(['slug']) as $source) {
            foreach ($fetchMethodPlan->preferenceValuesForSource((string) $source->slug) as $method => $preferred) {
                $points[] = [
                    'name' => MetricStreams::NAMES['fetch_method_preference'],
                    'value' => $preferred,
                    'labels' => ['source' => (string) $source->slug, 'method' => $method],
                ];
            }
        }

        return $points;
    }

    /** @return list<array{name: string, value: float|int, labels?: array<string, string>}> */
    private function fetchEgressMetrics(): array
    {
        if (! Schema::hasTable('crawl_fetch_attempts') || ! Schema::hasColumn('crawl_fetch_attempts', 'egress_ip')) {
            return [];
        }

        $since = now()->subMinute();
        $rows = app(ObservabilityServiceRepository::class)->tableQuery('crawl_fetch_attempts')
            ->where('created_at', '>=', $since)
            ->selectRaw('source_slug, method, egress_ip, outcome, COUNT(*) AS attempts')
            ->groupBy('source_slug', 'method', 'egress_ip', 'outcome')
            ->get();
        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'name' => MetricStreams::NAMES['fetch_attempts_by_egress'],
                'value' => (int) $row->attempts,
                'labels' => [
                    'source' => (string) $row->source_slug,
                    'method' => (string) $row->method,
                    'egress_ip' => is_string($row->egress_ip) ? $row->egress_ip : 'unknown',
                    'outcome' => (string) $row->outcome,
                ],
            ];
        }

        $egressRows = app(ObservabilityServiceRepository::class)->tableQuery('crawl_fetch_attempts')
            ->where('created_at', '>=', $since)
            ->selectRaw('egress_ip, SUM(CASE WHEN outcome <> ? THEN 1 ELSE 0 END) AS attempts', ['skipped'])
            ->selectRaw('SUM(CASE WHEN outcome <> ? AND (http_status IN (403, 429) OR NULLIF(cf_mitigated, ?) IS NOT NULL) THEN 1 ELSE 0 END) AS blocked', ['skipped', ''])
            ->groupBy('egress_ip')
            ->get();
        foreach ($egressRows as $row) {
            $attempts = max(0, (int) $row->attempts);
            $blocked = max(0, (int) $row->blocked);
            $points[] = [
                'name' => MetricStreams::NAMES['block_ratio_by_egress'],
                'value' => $attempts > 0 ? min(1.0, $blocked / $attempts) : 0.0,
                'labels' => ['egress_ip' => is_string($row->egress_ip) ? $row->egress_ip : 'unknown'],
            ];
        }

        return $points;
    }

    /** @return list<array{name: string, value: float|int, labels?: array<string, string>}> */
    private function nodeEgressMetrics(NodeEgressProbe $probe): array
    {
        $points = [];
        foreach ($probe->nodeStatuses() as $status) {
            $labels = ['node' => $status['node']];
            $points[] = [
                'name' => MetricStreams::NAMES['node_egress_changes_total'],
                'value' => $status['change_count'],
                'labels' => $labels,
            ];
            $points[] = [
                'name' => MetricStreams::NAMES['node_egress_probe_ok'],
                'value' => $status['probe_ok'] ? 1 : 0,
                'labels' => $labels,
            ];
            if ($status['ip'] !== null) {
                $points[] = [
                    'name' => MetricStreams::NAMES['node_egress_info'],
                    'value' => 1,
                    'labels' => ['node' => $status['node'], 'ip' => $status['ip']],
                ];
            }
        }

        return $points;
    }

    /** @return list<array{name: string, value: float|int, labels?: array<string, string>}> */
    private function browserEgressMetrics(NodeEgressProbe $probe): array
    {
        $points = [];
        foreach ($probe->browserStatuses() as $status) {
            $points[] = [
                'name' => MetricStreams::NAMES['browser_egress_probe_ok'],
                'value' => $status['probe_ok'] ? 1 : 0,
                'labels' => ['host' => $status['host']],
            ];
            if ($status['ip'] === null) {
                continue;
            }
            $points[] = [
                'name' => MetricStreams::NAMES['browser_egress_info'],
                'value' => 1,
                'labels' => ['host' => $status['host'], 'ip' => $status['ip']],
            ];
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function apiMetrics(): array
    {
        $since = now()->subMinute();
        $rows = app(ObservabilityServiceRepository::class)->modelQuery(ApiUsageLog::class)
            ->where('created_at', '>=', $since)
            ->get(['endpoint', 'status_code', 'response_ms']);

        if ($rows->isEmpty()) {
            return [
                ['name' => MetricStreams::NAMES['api_requests_1m'], 'value' => 0],
                ['name' => MetricStreams::NAMES['api_error_rate_1m'], 'value' => 0],
                ['name' => MetricStreams::NAMES['api_p50_ms_1m'], 'value' => 0],
                ['name' => MetricStreams::NAMES['api_p95_ms_1m'], 'value' => 0],
                ['name' => MetricStreams::NAMES['api_429_1m'], 'value' => 0],
            ];
        }

        $total = $rows->count();
        $errors = $rows->filter(fn($r) => (int) $r->status_code >= 400)->count();
        $rateLimited = $rows->filter(fn($r) => (int) $r->status_code === 429)->count();
        $latencies = $rows->pluck('response_ms')->filter(fn($ms) => $ms !== null)->map(fn($ms) => (int) $ms)->sort()->values();

        $points = [
            ['name' => MetricStreams::NAMES['api_requests_1m'], 'value' => $total],
            ['name' => MetricStreams::NAMES['api_error_rate_1m'], 'value' => $total > 0 ? $errors / $total : 0],
            ['name' => MetricStreams::NAMES['api_p50_ms_1m'], 'value' => $this->percentile($latencies->all(), 0.50)],
            ['name' => MetricStreams::NAMES['api_p95_ms_1m'], 'value' => $this->percentile($latencies->all(), 0.95)],
            ['name' => MetricStreams::NAMES['api_429_1m'], 'value' => $rateLimited],
        ];

        foreach ($rows->groupBy('endpoint') as $endpoint => $group) {
            $points[] = [
                'name' => MetricStreams::NAMES['api_requests_by_endpoint_1m'],
                'value' => $group->count(),
                'labels' => ['endpoint' => (string) $endpoint],
            ];
            $epLat = $group->pluck('response_ms')->filter(fn($ms) => $ms !== null)->map(fn($ms) => (int) $ms)->sort()->values()->all();
            $points[] = [
                'name' => MetricStreams::NAMES['api_p95_ms_by_endpoint_1m'],
                'value' => $this->percentile($epLat, 0.95),
                'labels' => ['endpoint' => (string) $endpoint],
            ];
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function qualityMetrics(): array
    {
        $points = [];

        $points[] = [
            'name' => MetricStreams::NAMES['movies_needs_review'],
            'value' => app(ObservabilityServiceRepository::class)->modelQuery(Movie::class)->where('needs_review', true)->count(),
        ];
        $points[] = [
            'name' => MetricStreams::NAMES['review_flags_open'],
            'value' => app(ObservabilityServiceRepository::class)->modelQuery(ReviewFlag::class)->whereNull('resolved_at')->count(),
        ];

        $tiers = app(ObservabilityServiceRepository::class)->modelQuery(Movie::class)
            ->selectRaw('completeness_tier, count(*) as c')
            ->groupBy('completeness_tier')
            ->get();
        foreach ($tiers as $tier) {
            $points[] = [
                'name' => MetricStreams::NAMES['movies_completeness_tier'],
                'value' => (int) $tier->getAttribute('c'),
                'labels' => ['tier' => (string) ($tier->completeness_tier ?? 'unknown')],
            ];
        }

        // Conflict rate per field: movies with >=2 distinct value_hash / movies with observations for field.
        $conflicts = app(ObservabilityServiceRepository::class)->observationConflictRates();

        foreach ($conflicts as $row) {
            $points[] = [
                'name' => MetricStreams::NAMES['observation_conflict_rate'],
                'value' => (float) ($row->conflict_rate ?? 0),
                'labels' => ['field' => (string) $row->field],
            ];
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function crawlRunMetrics(): array
    {
        $points = [];
        $since = now()->subHour();
        $rows = app(ObservabilityServiceRepository::class)->modelQuery(CrawlRun::class)
            ->where('started_at', '>=', $since)
            ->selectRaw('source_slug,
                COALESCE(SUM(pages_fetched),0) as pages_fetched,
                COALESCE(SUM(movies_new),0) as movies_new,
                COALESCE(SUM(movies_updated),0) as movies_updated,
                COALESCE(SUM(failures),0) as failures,
                COALESCE(SUM(proxy_requests),0) as proxy_requests,
                COALESCE(SUM(proxy_bytes),0) as proxy_bytes,
                COALESCE(SUM(browser_fetches),0) as browser_fetches')
            ->groupBy('source_slug')
            ->get();

        foreach ($rows as $row) {
            $labels = ['source_slug' => (string) $row->source_slug];
            foreach (['pages_fetched', 'movies_new', 'movies_updated', 'failures', 'proxy_requests', 'proxy_bytes', 'browser_fetches'] as $metric) {
                $points[] = [
                    'name' => MetricStreams::NAMES['crawl_run_' . $metric . '_1h'],
                    'value' => (int) $row->{$metric},
                    'labels' => $labels,
                ];
            }
        }

        return $points;
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function catalogMetrics(): array
    {
        return [
            ['name' => MetricStreams::NAMES['movies_total'], 'value' => app(ObservabilityServiceRepository::class)->modelQuery(Movie::class)->count()],
            ['name' => MetricStreams::NAMES['performers_total'], 'value' => app(ObservabilityServiceRepository::class)->modelQuery(Performer::class)->whereNull('canonical_performer_id')->count()],
            [
                'name' => MetricStreams::NAMES['observations_1d'],
                'value' => app(ObservabilityServiceRepository::class)->tableQuery('movie_observations')->where('crawled_at', '>=', now()->subDay())->count(),
            ],
        ];
    }

    /**
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function failedJobMetrics(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [['name' => MetricStreams::NAMES['failed_jobs'], 'value' => 0]];
        }

        return [
            ['name' => MetricStreams::NAMES['failed_jobs'], 'value' => app(ObservabilityServiceRepository::class)->tableQuery('failed_jobs')->count()],
            [
                'name' => MetricStreams::NAMES['failed_jobs_1h'],
                'value' => app(ObservabilityServiceRepository::class)->tableQuery('failed_jobs')->where('failed_at', '>=', now()->subHour())->count(),
            ],
        ];
    }

    /**
     * Report client backend usage against PostgreSQL's configured connection limit.
     *
     * @return list<array{name: string, value: float|int, labels?: array<string, string>}>
     */
    private function postgresCapacityMetrics(): array
    {
        $row = app(ObservabilityServiceRepository::class)->postgresConnectionCapacity();
        if ($row === null) {
            return [];
        }

        $used = max(0, (int) ($row->used ?? 0));
        $maximum = max(1, (int) ($row->maximum ?? 1));

        return [
            ['name' => MetricStreams::NAMES['postgres_connections_used'], 'value' => $used],
            ['name' => MetricStreams::NAMES['postgres_connections_max'], 'value' => $maximum],
            ['name' => MetricStreams::NAMES['postgres_connection_utilization'], 'value' => $used / $maximum],
        ];
    }

    /** @return list<array{name: string, value: float|int}> */
    private function postgresConnectionBudgetMetrics(HorizonWorkerLayout $layout, PostgresConnectionBudget $budget): array
    {
        $summary = $budget->summary($layout->configuredFleetWorkerCount());

        return [
            ['name' => MetricStreams::NAMES['postgres_connection_demand'], 'value' => $summary['demand']],
            ['name' => MetricStreams::NAMES['postgres_connection_role_limit'], 'value' => $summary['role_limit']],
            ['name' => MetricStreams::NAMES['postgres_connection_budget_limit'], 'value' => $summary['budget_limit']],
            ['name' => MetricStreams::NAMES['postgres_connection_demand_warning_threshold'], 'value' => $summary['warning_threshold']],
            ['name' => MetricStreams::NAMES['postgres_connection_demand_warning'], 'value' => $summary['warning'] ? 1 : 0],
        ];
    }

    private function dependencyProbeMetrics(ElasticsearchHttpClient $elasticsearch): array
    {
        $points = [];

        try {
            DB::connection()->getPdo();
            $points[] = ['name' => MetricStreams::NAMES['dep_up'], 'value' => 1, 'labels' => ['dependency' => 'postgres']];
        } catch (Throwable) {
            $points[] = ['name' => MetricStreams::NAMES['dep_up'], 'value' => 0, 'labels' => ['dependency' => 'postgres']];
        }

        try {
            $ok = $elasticsearch->get('metrics_probe', '/_cluster/health', 2)->successful();
            $points[] = ['name' => MetricStreams::NAMES['dep_up'], 'value' => $ok ? 1 : 0, 'labels' => ['dependency' => 'elasticsearch']];
        } catch (Throwable) {
            $points[] = ['name' => MetricStreams::NAMES['dep_up'], 'value' => 0, 'labels' => ['dependency' => 'elasticsearch']];
        }

        $ooUrl = rtrim((string) config('openobserve.url'), '/');
        try {
            $response = Http::timeout(2)->get("{$ooUrl}/healthz");
            $ok = $response->successful() || $response->status() < 500;
            $points[] = ['name' => MetricStreams::NAMES['dep_up'], 'value' => $ok ? 1 : 0, 'labels' => ['dependency' => 'openobserve']];
        } catch (Throwable) {
            $points[] = ['name' => MetricStreams::NAMES['dep_up'], 'value' => 0, 'labels' => ['dependency' => 'openobserve']];
        }

        $mongoHost = (string) config('mongodb.host', '127.0.0.1');
        $mongoPort = (int) config('mongodb.port', 27017);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($mongoHost, $mongoPort, $errno, $errstr, 1.0);
        $mongoUp = $fp !== false;
        $points[] = [
            'name' => MetricStreams::NAMES['dep_up'],
            'value' => $mongoUp ? 1 : 0,
            'labels' => ['dependency' => 'mongo'],
        ];
        if ($mongoUp) {
            fclose($fp);
        }

        return $points;
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        sort($sorted);
        $index = (int) max(0, min(count($sorted) - 1, (int) floor($p * (count($sorted) - 1))));

        return (float) $sorted[$index];
    }
}

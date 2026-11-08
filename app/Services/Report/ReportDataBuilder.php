<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Repositories\ReportDataServiceRepository;
use App\Services\Notifications\NotificationPolicyService;
use Carbon\CarbonImmutable;
use Throwable;

final class ReportDataBuilder
{
    public function __construct(
        private readonly NodeSampleStore $samples,
        private readonly NodeSampleAggregator $sampleAggregator,
        private readonly ReportServiceStats $serviceStats,
        private readonly NotificationPolicyService $notificationPolicies,
        private readonly ReportDataServiceRepository $repository,
    ) {}

    /** @return array<string, mixed> */
    public function build(?CarbonImmutable $at = null, ?int $windowMinutes = null): array
    {
        $at ??= CarbonImmutable::now();
        $minutes = $windowMinutes ?? (int) config('javmeta_report.interval_minutes', 120);
        $start = $at->subMinutes($minutes);
        $previous = $this->repository->previousSentPeriodic();
        $previousValue = $previous['raw_numbers'] ?? null;
        $previousRaw = is_array($previousValue) ? $previousValue : null;
        $periodicDigestSince = $previous['created_at'] ?? null;
        if (! $periodicDigestSince instanceof \DateTimeInterface) {
            $periodicDigestSince = $start;
        }
        $daily = $windowMinutes !== null;
        $digestSince = $daily ? $start : $periodicDigestSince;
        $raw = [
            'generated_at' => $at->toIso8601String(),
            'window_minutes' => $minutes,
            'report_type' => $windowMinutes === null ? 'periodic' : 'daily',
            'window_start' => $start->toIso8601String(),
            'window_end' => $at->toIso8601String(),
            'sections' => $this->sections(),
        ];

        if (in_array('movies', $this->sections(), true)) {
            $raw['movies'] = $this->entity('movies', 'crawled_at', $this->repository->entityCount('movies'), $previousRaw, $start, $at, $minutes, $windowMinutes !== null);
        }
        if (in_array('performers', $this->sections(), true)) {
            $raw['performers'] = $this->entity('performers', 'first_seen_at', $this->repository->entityCount('performers'), $previousRaw, $start, $at, $minutes, $windowMinutes !== null);
        }
        if (in_array('crawl', $this->sections(), true)) {
            $raw['crawl'] = $this->crawl($start, $at);
        }
        if (in_array('sources', $this->sections(), true)) {
            $raw['sources'] = $this->sources($start, $at);
        }
        $raw['warnings'] = $this->notificationPolicies->digestItems($daily, null, true);
        $digestItems = $this->notificationPolicies->digestItems($daily, $digestSince, false, $at);
        $raw['notification_events'] = array_values(array_filter(
            $digestItems,
            static fn(array $item): bool => in_array($item['tier'], ['info', 'notice'], true)
                && ! in_array($item['key'], ['deploy.ok', 'deploy.success'], true),
        ));
        $raw['deploys'] = $this->deploys(array_values(array_filter(
            $digestItems,
            static fn(array $item): bool => in_array($item['key'], ['deploy.ok', 'deploy.success'], true),
        )));
        $raw['backup'] = $this->backupStatus($at);

        $nodeSamples = $this->samples->all();
        $raw['node_names'] = array_keys($nodeSamples);
        $raw['nodes'] = $this->sampleAggregator->aggregate(
            $nodeSamples,
            $start,
            $at,
            max(60, (int) config('javmeta_report.sample_max_age_seconds', 180)),
        );
        if (in_array('fleet', $this->sections(), true)) {
            $raw['fleet'] = $this->fleet($raw['nodes'], $start, $at);
        }
        if (in_array('workers', $this->sections(), true)) {
            $raw['workers'] = $raw['nodes'];
        }
        if (in_array('servers', $this->sections(), true)) {
            $raw['servers'] = $raw['nodes'];
        }
        $serviceStats = [];
        if (in_array('services', $this->sections(), true) || in_array('servers', $this->sections(), true)) {
            $serviceStats = $this->serviceStats->collect();
        }
        if (in_array('services', $this->sections(), true)) {
            $raw['services'] = $serviceStats;
        }
        if (in_array('servers', $this->sections(), true)) {
            $raw['server_extra'] = $serviceStats;
        }

        return $raw;
    }

    /** @return list<string> */
    private function sections(): array
    {
        return array_values((array) config('javmeta_report.sections', []));
    }

    /** @param array<string, mixed>|null $previousRaw
     *  @return array<string, int|float>
     */
    private function entity(string $table, string $dateColumn, int $total, ?array $previousRaw, CarbonImmutable $start, CarbonImmutable $at, int $minutes, bool $windowCount): array
    {
        $lastReportTotal = (int) ($previousRaw[$table]['total'] ?? $total);
        $since = $windowCount
            ? $this->repository->entityCountBetween($table, $dateColumn, $start, $at)
            : max(0, $total - $lastReportTotal);
        $last24 = $windowCount && $minutes === 1440
            ? $since
            : $this->repository->entityCountLastDay($table, $dateColumn, $at);
        $buckets = $this->hourBuckets($table, $dateColumn, $start, $at);
        $hours = max(1 / 60, $minutes / 60);
        $scale = $minutes < 60 ? 60 / $minutes : 1;

        return [
            'total' => $total,
            'since_last' => $windowCount ? $since : $total - $lastReportTotal,
            'last_24h' => $last24,
            'avg_per_hour' => round($since / $hours, 2),
            'peak_per_hour' => round(($buckets === [] ? 0 : max($buckets)) * $scale, 2),
        ];
    }

    /** @return list<int> */
    private function hourBuckets(string $table, string $dateColumn, CarbonImmutable $start, CarbonImmutable $at): array
    {
        return $this->repository->entityHourBuckets($table, $dateColumn, $start, $at);
    }

    /** @return array<string, array{attempts: int, success_percent: float, blocked_percent: float}> */
    private function crawl(CarbonImmutable $start, CarbonImmutable $at): array
    {
        $rows = $this->repository->crawlAttemptCounts([
            'listing', 'detail', 'gallery', 'performer_listing', 'performer_detail',
        ], $start, $at);
        $result = [];
        foreach ([
            'movies' => ['listing', 'detail', 'gallery'],
            'performers' => ['performer_listing', 'performer_detail'],
        ] as $entity => $kinds) {
            $attempts = $counted = $successes = $blocked = 0;
            foreach ($kinds as $kind) {
                $row = $rows[$kind] ?? [];
                $attempts += $row['attempts'] ?? 0;
                $counted += $row['counted'] ?? 0;
                $successes += $row['successes'] ?? 0;
                $blocked += $row['blocked'] ?? 0;
            }
            if ($attempts > 0) {
                $result[$entity] = [
                    'attempts' => $attempts,
                    'success_percent' => $counted > 0 ? round(($successes / $counted) * 100, 1) : 0.0,
                    'blocked_percent' => round(($blocked / $attempts) * 100, 1),
                ];
            }
        }

        return $result;
    }

    /** @return array{blocked: list<string>, exhausted: list<string>} */
    private function sources(CarbonImmutable $start, CarbonImmutable $at): array
    {
        $limit = max(1, min(5, (int) config('javmeta_report.top_sources_limit', 5)));
        $blocked = $this->repository->topBlockedSources($start, $at, $limit);
        $exhausted = $this->repository->topExhaustedSources($start, $at, $limit);

        return ['blocked' => $blocked, 'exhausted' => $exhausted];
    }

    /** @param list<array<string, mixed>> $events
     *  @return list<array{at: string, release: string, nodes: string}>
     */
    private function deploys(array $events): array
    {
        return array_map(static function (array $event): array {
            $context = is_array($event['context'] ?? null) ? $event['context'] : [];

            return [
                'at' => (string) ($event['opened_at'] ?? ''),
                'release' => is_scalar($context['release'] ?? null) ? (string) $context['release'] : 'release',
                'nodes' => is_scalar($context['nodes'] ?? null) ? (string) $context['nodes'] : 'fleet',
            ];
        }, array_slice($events, 0, 20));
    }

    /** @return array{status: string, at: string|null} */
    private function backupStatus(CarbonImmutable $at): array
    {
        $manifestPath = trim((string) config('backup.manifest_path', ''));
        if ($manifestPath !== '' && is_file($manifestPath) && is_readable($manifestPath)) {
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                $createdAt = $manifest['created_at'] ?? null;
                if (is_string($createdAt) && $createdAt !== '') {
                    $backupAt = CarbonImmutable::parse($createdAt);
                    $ageSeconds = max(0, $at->getTimestamp() - $backupAt->getTimestamp());
                    $threshold = max(1, (int) config('backup.stale_after_seconds', 26 * 60 * 60));

                    return [
                        'status' => $ageSeconds > $threshold ? 'Failed or stale' : 'Successful',
                        'at' => $backupAt->toIso8601String(),
                    ];
                }
            } catch (Throwable) {
                // Fall back to the most recent recorded backup alert when the manifest cannot be read.
            }
        }
        $row = $this->repository->latestBackupPolicyEvent($at);
        if ($row === null) {
            return ['status' => 'No recent backup status recorded', 'at' => null];
        }

        return [
            'status' => $row['resolved_at'] !== null || ! in_array($row['notification_key'], ['backup.stale', 'backup.manifest_missing'], true)
                ? 'Successful'
                : 'Failed or stale',
            'at' => $row['resolved_at'] !== null && in_array($row['notification_key'], ['backup.stale', 'backup.manifest_missing'], true)
                ? $row['resolved_at']
                : $row['first_seen_at'],
        ];
    }

    /** @param array<string, array<string, mixed>> $nodes
     *  @return array{nodes_running: int, failed_jobs: int}
     */
    private function fleet(array $nodes, CarbonImmutable $start, CarbonImmutable $at): array
    {
        $failedJobs = $this->repository->failedJobsCount($start, $at);

        return ['nodes_running' => count($nodes), 'failed_jobs' => $failedJobs];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Repositories\CrawlStatusServiceRepository;
use App\Repositories\SourceCircuitBreakerRepository;
use App\Repositories\SourceRepository;
use DateTimeInterface;

/**
 * Aggregate crawl pipeline status for monitoring (MCP `crawl_status`).
 */
final class CrawlStatusService
{
    public function __construct(
        private readonly WorkerHeartbeat $heartbeat,
        private readonly ?CrawlStatusServiceRepository $statusRepository = null,
        private readonly ?SourceRepository $sourceRepository = null,
    ) {}

    /**
     * @return array{movies: int, performers: int}
     */
    public function counts(): array
    {
        return $this->statusRepository()->counts();
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
            'ingest' => $this->statusRepository()->ingestCounts(now()->subHour(), now()->subDay()),
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
        return $this->statusRepository()->queueStatus($claimCutoff);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceStatus(): array
    {
        $rows = [];
        app(SourceCircuitBreaker::class)->pausedSourceSlugs();
        $lanes = app(CrawlLaneService::class);
        $browser = $lanes->browserSources();
        $circuits = app(SourceCircuitBreakerRepository::class)->statusBySlug();
        foreach ($this->sourceRepository()->statusRows() as $source) {
            $circuit = $circuits[$source['slug']] ?? [
                'circuit_state' => 'closed',
                'consecutive_failures' => 0,
                'circuit_opened_at' => null,
                'circuit_cooldown_seconds' => null,
            ];
            $rows[] = [
                'slug' => $source['slug'],
                'name' => $source['name'],
                'enabled' => $source['enabled'],
                'lane' => config('javmeta_queues.lanes_enabled', true) && in_array($source['slug'], $browser, true) ? 'browser' : 'direct',
                'circuit_state' => $circuit['circuit_state'],
                'consecutive_failures' => $circuit['consecutive_failures'],
                'circuit_cooldown_seconds' => $circuit['circuit_cooldown_seconds'],
                'circuit_resume_at' => $this->circuitResumeAt($circuit['circuit_opened_at'], $circuit['circuit_cooldown_seconds']),
                'gap_seconds_current' => $source['gap_seconds_current'],
                'gap_seconds_min' => $source['gap_seconds_min'],
                'gap_seconds_max' => $source['gap_seconds_max'],
                'last_success_at' => $this->dateTime($source['last_success_at']),
                'last_error_at' => $this->dateTime($source['last_error_at']),
                'last_error' => $source['last_error'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workerStatus(DateTimeInterface $claimCutoff): array
    {
        $activeClaims = $this->statusRepository()->activeClaimsByWorker($claimCutoff);

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
        return $this->statusRepository()->runTotals(now()->subDay());
    }

    private function statusRepository(): CrawlStatusServiceRepository
    {
        return $this->statusRepository ?? app(CrawlStatusServiceRepository::class);
    }

    private function sourceRepository(): SourceRepository
    {
        return $this->sourceRepository ?? app(SourceRepository::class);
    }

    private function dateTime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }

    private function circuitResumeAt(mixed $openedAt, ?int $cooldownSeconds): ?string
    {
        if (! $openedAt instanceof DateTimeInterface || $cooldownSeconds === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::instance($openedAt)->addSeconds($cooldownSeconds)->toIso8601String();
    }
}

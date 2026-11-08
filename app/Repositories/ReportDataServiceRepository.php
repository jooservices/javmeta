<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;
use Throwable;

/** Persistence operations for periodic and daily reports. */
final class ReportDataServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(ReportSnapshot $model)
    {
        parent::__construct($model);
    }

    /** @return array{raw_numbers: mixed, created_at: mixed}|null */
    public function previousSentPeriodic(): ?array
    {
        $snapshot = ReportSnapshot::query()->where('report_type', 'periodic')->where('result', 'sent')->orderByDesc('id')->first();
        if ($snapshot === null) {
            return null;
        }

        return ['raw_numbers' => $snapshot->getAttribute('raw_numbers'), 'created_at' => $snapshot->getAttribute('created_at')];
    }

    public function entityCount(string $table): int
    {
        return DB::table($table)->count();
    }

    public function entityCountBetween(string $table, string $dateColumn, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return DB::table($table)->whereBetween($dateColumn, [$start, $end])->count();
    }

    public function entityCountLastDay(string $table, string $dateColumn, CarbonImmutable $end): int
    {
        return DB::table($table)->where($dateColumn, '>=', $end->subDay())->where($dateColumn, '<=', $end)->count();
    }

    /** @return list<int> */
    public function entityHourBuckets(string $table, string $dateColumn, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $bucket = match (DB::connection()->getDriverName()) {
            'pgsql' => "date_trunc('hour', {$dateColumn})",
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', {$dateColumn})",
            default => "DATE_FORMAT({$dateColumn}, '%Y-%m-%d %H:00:00')",
        };
        $rows = DB::table($table)->whereBetween($dateColumn, [$start, $end])->whereNotNull($dateColumn)
            ->selectRaw($bucket . ' AS hour_bucket, COUNT(*) AS items')->groupBy('hour_bucket')->get();

        return $rows->map(static fn(object $row): int => (int) $row->items)->all();
    }

    /** @param list<string> $kinds
     *  @return array<string, array{attempts: int, counted: int, successes: int, blocked: int}>
     */
    public function crawlAttemptCounts(array $kinds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! Schema::hasTable('crawl_fetch_attempts')) {
            return [];
        }
        $rows = DB::table('crawl_fetch_attempts')->whereIn('kind', $kinds)->whereBetween('started_at', [$start, $end])
            ->selectRaw("kind, COUNT(*) AS attempts, SUM(CASE WHEN outcome <> 'skipped' THEN 1 ELSE 0 END) AS counted, SUM(CASE WHEN outcome <> 'skipped' AND http_status >= 200 AND http_status < 300 THEN 1 ELSE 0 END) AS successes, SUM(CASE WHEN http_status IN (403, 429) OR cf_mitigated IS NOT NULL THEN 1 ELSE 0 END) AS blocked")
            ->groupBy('kind')->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->kind] = [
                'attempts' => (int) $row->attempts,
                'counted' => (int) $row->counted,
                'successes' => (int) $row->successes,
                'blocked' => (int) $row->blocked,
            ];
        }

        return $counts;
    }

    /** @return list<string> */
    public function topBlockedSources(CarbonImmutable $start, CarbonImmutable $end, int $limit): array
    {
        if (! Schema::hasTable('crawl_fetch_attempts')) {
            return [];
        }

        return DB::table('crawl_fetch_attempts')
            ->whereIn('kind', [CrawlQueue::KIND_LISTING, CrawlQueue::KIND_DETAIL, CrawlQueue::KIND_GALLERY, CrawlQueue::KIND_PERFORMER_LISTING, CrawlQueue::KIND_PERFORMER_DETAIL])
            ->whereBetween('started_at', [$start, $end])
            ->where(static fn($query) => $query->whereIn('http_status', [403, 429])->orWhereNotNull('cf_mitigated'))
            ->select('source_slug')->selectRaw('COUNT(*) AS blocked_count')->groupBy('source_slug')
            ->orderByDesc('blocked_count')->orderBy('source_slug')->limit($limit)->pluck('source_slug')->map('strval')->all();
    }

    /** @return list<string> */
    public function topExhaustedSources(CarbonImmutable $start, CarbonImmutable $end, int $limit): array
    {
        return CrawlQueue::query()->where('status', CrawlQueue::STATUS_FAILED)->whereBetween('last_failed_at', [$start, $end])
            ->whereColumn('attempts', '>=', 'max_attempts')->select('source_slug')->selectRaw('COUNT(*) AS exhausted_count')
            ->groupBy('source_slug')->orderByDesc('exhausted_count')->orderBy('source_slug')->limit($limit)->pluck('source_slug')->map('strval')->all();
    }

    /** @return array{notification_key: string, first_seen_at: string, resolved_at: string|null}|null */
    public function latestBackupPolicyEvent(DateTimeInterface $at): ?array
    {
        if (! Schema::hasTable('notification_policy_events')) {
            return null;
        }
        $row = DB::table('notification_policy_events')->whereIn('notification_key', ['backup.stale', 'backup.manifest_missing', 'backup.success', 'backup.ok'])
            ->where('first_seen_at', '<=', $at)->orderByDesc('first_seen_at')->first(['notification_key', 'first_seen_at', 'resolved_at']);

        return $row === null ? null : [
            'notification_key' => (string) $row->notification_key,
            'first_seen_at' => (string) $row->first_seen_at,
            'resolved_at' => $row->resolved_at === null ? null : (string) $row->resolved_at,
        ];
    }

    public function failedJobsCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->whereBetween('failed_at', [$start, $end])->count() : 0;
    }

    public function lastScheduledAt(): ?string
    {
        $scheduledAt = ReportSnapshot::query()->where('report_type', 'periodic')->whereNotNull('scheduled_for')->max('scheduled_for');

        return $scheduledAt === null ? null : (string) $scheduledAt;
    }

    public function snapshotExists(string $type, DateTimeInterface $scheduledFor): bool
    {
        return ReportSnapshot::query()->where('report_type', $type)->where('scheduled_for', $scheduledFor)->exists();
    }

    /** @return array<string, mixed>|null */
    public function latestSnapshot(string $type): ?array
    {
        $snapshot = ReportSnapshot::query()->where('report_type', $type)->orderByDesc('id')->first();

        return $snapshot === null ? null : $this->snapshotData($snapshot);
    }

    /** @return list<array<string, mixed>> */
    public function recentSnapshots(string $type, int $limit): array
    {
        return ReportSnapshot::query()->where('report_type', $type)->orderByDesc('id')->limit($limit)->get()
            ->map(fn(ReportSnapshot $snapshot): array => $this->snapshotData($snapshot))->all();
    }

    /** @param array<string, mixed> $attributes */
    public function createSnapshot(array $attributes): void
    {
        ReportSnapshot::query()->create($attributes);
    }

    public function pruneSnapshotsBefore(DateTimeInterface $cutoff): void
    {
        ReportSnapshot::query()->where('created_at', '<', $cutoff)->delete();
    }

    /** @return array{used: int, maximum: int}|null */
    public function postgresStats(): ?array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }
        try {
            // PostgreSQL-specific server statistics; ReportServiceStatsTest exercises this on real PostgreSQL.
            $row = DB::selectOne("SELECT COUNT(*)::int AS used, current_setting('max_connections')::int AS maximum FROM pg_stat_activity WHERE backend_type = 'client backend'");

            return ['used' => max(0, (int) ($row->used ?? 0)), 'maximum' => max(1, (int) ($row->maximum ?? 1))];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function snapshotData(ReportSnapshot $snapshot): array
    {
        return [
            'id' => (int) $snapshot->getKey(),
            'result' => (string) $snapshot->getAttribute('result'),
            'channel' => (string) $snapshot->getAttribute('channel'),
            'scheduled_for' => $snapshot->getAttribute('scheduled_for'),
            'created_at' => $snapshot->getAttribute('created_at'),
            'rendered_text' => (string) $snapshot->getAttribute('rendered_text'),
            'raw_numbers' => $snapshot->getAttribute('raw_numbers'),
        ];
    }
}

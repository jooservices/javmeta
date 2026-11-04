<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Persistence queries used to construct alert snapshots. */
final class AlertSnapshotServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }

    /** @return array<string, array{attempts: int, successes: int, blocked: int}> */
    public function attemptsBySource(CarbonImmutable $cutoff, CarbonImmutable $now): array
    {
        $rows = DB::table('crawl_fetch_attempts')
            ->whereBetween('started_at', [$cutoff, $now])
            ->where('outcome', '<>', 'skipped')
            ->selectRaw('source_slug, COUNT(*) AS attempts, SUM(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS successes, SUM(CASE WHEN http_status IN (403, 429) OR NULLIF(cf_mitigated, ?) IS NOT NULL THEN 1 ELSE 0 END) AS blocked', [''])
            ->groupBy('source_slug')
            ->get();
        $attempts = [];

        foreach ($rows as $row) {
            $attempts[(string) $row->source_slug] = [
                'attempts' => (int) $row->attempts,
                'successes' => (int) $row->successes,
                'blocked' => (int) $row->blocked,
            ];
        }

        return $attempts;
    }

    /** @return array<string, int> */
    public function dueBySource(): array
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

    /** @return list<string> */
    public function enabledSourceSlugs(): array
    {
        return Source::query()->where('enabled', true)->orderBy('slug')->pluck('slug')->map('strval')->all();
    }

    /** @param list<string> $sourcesWithAttempts
     *  @return list<string>
     */
    public function hotSourceSlugs(CarbonImmutable $cutoff, array $sourcesWithAttempts): array
    {
        $query = Source::query()->where('enabled', true)->where(static function ($query) use ($cutoff, $sourcesWithAttempts): void {
            $query->where('last_success_at', '>=', $cutoff);
            if ($sourcesWithAttempts !== []) {
                $query->orWhereIn('slug', $sourcesWithAttempts);
            }
        });

        return $query->orderBy('slug')->pluck('slug')->map('strval')->all();
    }

    public function postgresConnections(): ?int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }

        // PostgreSQL-only server metric; AlertSnapshotReaderTest exercises this path on real PostgreSQL.
        $row = DB::selectOne("SELECT COUNT(*)::int AS used FROM pg_stat_activity WHERE backend_type = 'client backend'");

        return max(0, (int) ($row->used ?? 0));
    }
}

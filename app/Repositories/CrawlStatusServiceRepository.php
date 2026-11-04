<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use App\Models\Movie;
use App\Models\Performer;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Read models for the crawl status response. */
final class CrawlStatusServiceRepository extends EloquentRepository implements RepositoryInterface
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

    /** @return array{movies: int, performers: int} */
    public function counts(): array
    {
        return [
            'movies' => (int) Movie::query()->count(),
            'performers' => (int) Performer::query()->whereNull('canonical_performer_id')->count(),
        ];
    }

    /** @return array{movies_1h: int, movies_24h: int, performers_1h: int, performers_24h: int} */
    public function ingestCounts(DateTimeInterface $sinceHour, DateTimeInterface $sinceDay): array
    {
        return [
            'movies_1h' => (int) Movie::query()->where('first_seen_at', '>=', $sinceHour)->count(),
            'movies_24h' => (int) Movie::query()->where('first_seen_at', '>=', $sinceDay)->count(),
            'performers_1h' => (int) Performer::query()->where('first_seen_at', '>=', $sinceHour)->count(),
            'performers_24h' => (int) Performer::query()->where('first_seen_at', '>=', $sinceDay)->count(),
        ];
    }

    /** @return array{by_status: array<string, int>, by_kind_status: list<array{kind: string, status: string, count: int}>, oldest_pending_at: string|null, stuck_claimed: int} */
    public function queueStatus(DateTimeInterface $claimCutoff): array
    {
        $queueByStatus = $this->newQuery()
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
            ->map(static fn(object $row): array => [
                'kind' => (string) $row->kind,
                'status' => (string) $row->status,
                'count' => (int) $row->total,
            ])
            ->all();

        $oldestPending = $this->newQuery()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->orderBy('next_attempt_at')
            ->value('next_attempt_at');

        return [
            'by_status' => $queueByStatus,
            'by_kind_status' => $pendingKinds,
            'oldest_pending_at' => $oldestPending instanceof DateTimeInterface ? $oldestPending->format(DATE_ATOM) : null,
            'stuck_claimed' => $this->newQuery()
                ->where('status', CrawlQueue::STATUS_CLAIMED)
                ->where('claimed_at', '<', $claimCutoff)
                ->count(),
        ];
    }

    /** @return array<string, int> */
    public function activeClaimsByWorker(DateTimeInterface $claimCutoff): array
    {
        return $this->newQuery()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->where('claimed_at', '>=', $claimCutoff)
            ->select('locked_by', DB::raw('count(*) as total'))
            ->groupBy('locked_by')
            ->pluck('total', 'locked_by')
            ->map(static fn(mixed $value): int => (int) $value)
            ->all();
    }

    /** @return array{runs: int, pages_fetched: int, movies_new: int, movies_updated: int, failures: int} */
    public function runTotals(DateTimeInterface $since): array
    {
        $runs = CrawlRun::query()
            ->where('started_at', '>=', $since)
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
}

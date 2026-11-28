<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Crawl runs, their fetch attempts, the queue, the error table and the crawler event feed. */
final class CrawlLogRepository
{
    private const RUN_COLUMNS = ['id', 'source_slug', 'started_at', 'finished_at', 'status', 'pages_fetched', 'movies_new', 'movies_updated', 'failures', 'browser_fetches'];

    private const EVENT_COLUMNS = ['id', 'source_slug', 'kind', 'url', 'detail', 'created_at'];

    /** @return list<stdClass> */
    public function runs(?string $source, ?string $status, int $limit, int $offset): array
    {
        return $this->runFilter($source, $status)->orderByDesc('started_at')->orderByDesc('id')->limit($limit)->offset($offset)->get(self::RUN_COLUMNS)->all();
    }

    /** The number of runs matching the filter, counted up to 100,000 so the page stays cheap on the full table. */
    public function runCount(?string $source, ?string $status): int
    {
        return DB::query()->fromSub($this->runFilter($source, $status)->select('id')->limit(100000), 't')->count();
    }

    public function run(int $id): ?stdClass
    {
        return DB::table('crawl_runs')->where('id', $id)->first();
    }

    /** @return list<stdClass> */
    public function attemptsOfRun(stdClass $run): array
    {
        $from = CarbonImmutable::parse((string) $run->started_at, 'UTC');
        $to = $run->finished_at === null ? $from->addMinutes(10) : CarbonImmutable::parse((string) $run->finished_at, 'UTC');

        return DB::table('crawl_fetch_attempts')
            ->where('source_slug', $run->source_slug)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit(200)
            ->get(['created_at', 'method', 'duration_ms', 'http_status', 'outcome', 'error_code', 'lane', 'node'])
            ->all();
    }

    /** @return list<stdClass> */
    public function queue(CarbonInterface $staleBefore): array
    {
        return DB::table('crawl_queue')
            ->whereIn('status', ['pending', 'claimed', 'failed'])
            ->groupBy('source_slug', 'status')
            ->orderByDesc('n')
            ->limit(120)
            ->selectRaw("source_slug, status, COUNT(*) AS n, MIN(next_attempt_at) AS oldest_next, MIN(claimed_at) AS oldest_claim, COALESCE(SUM(CASE WHEN status = 'claimed' AND claimed_at < ? THEN 1 ELSE 0 END), 0) AS stale", [$staleBefore])
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    public function errorsSince(CarbonInterface $since): array
    {
        return DB::table('crawl_fetch_attempts')
            ->where('created_at', '>', $since)
            ->where('outcome', '<>', 'ok')
            ->groupBy('source_slug', DB::raw('COALESCE(error_code, outcome)'))
            ->orderByDesc('n')
            ->limit(60)
            ->selectRaw('source_slug, COALESCE(error_code, outcome) AS error_code, COUNT(*) AS n, MAX(created_at) AS last_at')
            ->get()
            ->all();
    }

    /**
     * @param list<string> $kinds
     * @return list<stdClass>
     */
    public function events(array $kinds, ?string $source, int $limit, int $offset): array
    {
        return DB::table('crawl_events')
            ->when($kinds !== [], static fn(Builder $q): Builder => $q->whereIn('kind', $kinds))
            ->when($source !== null, static fn(Builder $q): Builder => $q->where('source_slug', $source))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset)
            ->get(self::EVENT_COLUMNS)
            ->all();
    }

    /** @return list<stdClass> events of the last days, by kind, most frequent first */
    public function eventKindsSince(CarbonInterface $since): array
    {
        return DB::table('crawl_events')
            ->where('created_at', '>', $since)
            ->groupBy('kind')
            ->orderByDesc('n')
            ->selectRaw('kind, COUNT(*) AS n')
            ->get()
            ->all();
    }

    /** @return list<stdClass> events newer than the id (or, with no id, than the time), oldest first */
    public function eventsAfter(?int $afterId, CarbonInterface $since, int $limit): array
    {
        return DB::table('crawl_events')
            ->when($afterId !== null, static fn(Builder $q): Builder => $q->where('id', '>', $afterId), static fn(Builder $q): Builder => $q->where('created_at', '>', $since))
            ->orderBy('id')
            ->limit($limit)
            ->get(self::EVENT_COLUMNS)
            ->all();
    }

    private function runFilter(?string $source, ?string $status): Builder
    {
        return DB::table('crawl_runs')
            ->when($source !== null, static fn(Builder $q): Builder => $q->where('source_slug', $source))
            ->when($status !== null, static fn(Builder $q): Builder => $q->where('status', $status));
    }
}

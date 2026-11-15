<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Read-only database queries for owner quality, crawl cost, and source reports. */
final class OwnerReportServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Movie $model)
    {
        parent::__construct($model);
    }

    /** @param callable(list<array<string, mixed>>): void $consume */
    public function forEachQualityChunk(callable $consume): void
    {
        DB::table('movies')->select(['id', 'display_code', 'title_jp', 'title_en', 'cover_url', 'release_date', 'runtime_minutes', 'maker', 'label', 'series', 'completeness_tier'])
            ->orderBy('id')->chunk(1000, static function ($chunk) use ($consume): void {
                $ids = $chunk->pluck('id')->all();
                $withActresses = DB::table('movie_performers')->whereIn('movie_id', $ids)->distinct()->pluck('movie_id')->flip()->all();
                $withGenres = DB::table('movie_genres')->whereIn('movie_id', $ids)->distinct()->pluck('movie_id')->flip()->all();
                $rows = [];
                foreach ($chunk as $movie) {
                    $rows[] = [
                        'id' => (int) $movie->id,
                        'display_code' => $movie->display_code,
                        'title_jp' => $movie->title_jp,
                        'title_en' => $movie->title_en,
                        'cover_url' => $movie->cover_url,
                        'release_date' => $movie->release_date,
                        'runtime_minutes' => $movie->runtime_minutes,
                        'maker' => $movie->maker,
                        'label' => $movie->label,
                        'series' => $movie->series,
                        'completeness_tier' => $movie->completeness_tier,
                        'has_actresses' => isset($withActresses[$movie->id]),
                        'has_genres' => isset($withGenres[$movie->id]),
                    ];
                }
                $consume($rows);
            });
    }

    public function performerCount(): int
    {
        return DB::table('performers')->count();
    }

    /** @return array<string, int> */
    public function moviesBySource(): array
    {
        $rows = DB::table('movie_observations')->select('source_slug', DB::raw('COUNT(DISTINCT movie_id) AS movies'))
            ->groupBy('source_slug')->orderBy('source_slug')->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->source_slug] = (int) $row->movies;
        }

        return $counts;
    }

    public function needsReviewCount(): int
    {
        return DB::table('movies')->where('needs_review', true)->count();
    }

    /** @return list<array{source_slug: string, pages: int, failures: int, proxy_requests: int, movies: int}> */
    public function crawlRunTotals(): array
    {
        return DB::table('crawl_runs')
            ->select('source_slug', DB::raw('SUM(pages_fetched) AS pages'), DB::raw('SUM(failures) AS failures'), DB::raw('SUM(proxy_requests) AS proxy_requests'), DB::raw('SUM(movies_new + movies_updated) AS movies'))
            ->groupBy('source_slug')->orderBy('source_slug')->get()
            ->map(static fn(object $row): array => [
                'source_slug' => (string) $row->source_slug,
                'pages' => (int) $row->pages,
                'failures' => (int) $row->failures,
                'proxy_requests' => (int) $row->proxy_requests,
                'movies' => (int) $row->movies,
            ])->all();
    }

    /** @return list<array{source_slug: string, error_code: string|null, rows_failed: int}> */
    public function failedQueueCounts(): array
    {
        return DB::table('crawl_queue')->where('status', 'failed')->select('source_slug', 'error_code', DB::raw('COUNT(*) AS rows_failed'))
            ->groupBy('source_slug', 'error_code')->get()
            ->map(static fn(object $row): array => [
                'source_slug' => (string) $row->source_slug,
                'error_code' => $row->error_code === null ? null : (string) $row->error_code,
                'rows_failed' => (int) $row->rows_failed,
            ])->all();
    }

    /** @return list<array{source_slug: string, started_at: string, finished_at: string}> */
    public function finishedCrawlRuns(): array
    {
        return DB::table('crawl_runs')->whereNotNull('finished_at')->get(['source_slug', 'started_at', 'finished_at'])
            ->map(static fn(object $row): array => [
                'source_slug' => (string) $row->source_slug,
                'started_at' => (string) $row->started_at,
                'finished_at' => (string) $row->finished_at,
            ])->all();
    }

    /** @return list<array{slug: string, enabled: bool, last_success_at: string|null, last_error_at: string|null, last_error: string|null}> */
    public function sourceStatusRows(): array
    {
        return DB::table('sources')->orderBy('priority')->get(['slug', 'enabled', 'last_success_at', 'last_error_at', 'last_error'])
            ->map(static fn(object $row): array => [
                'slug' => (string) $row->slug,
                'enabled' => (bool) $row->enabled,
                'last_success_at' => $row->last_success_at === null ? null : (string) $row->last_success_at,
                'last_error_at' => $row->last_error_at === null ? null : (string) $row->last_error_at,
                'last_error' => $row->last_error === null ? null : (string) $row->last_error,
            ])->all();
    }

    /** @return array{movies_with_a_conflict: int, by_field: array<string, int>} */
    public function fieldConflictSummary(): array
    {
        $conflicts = static fn() => DB::table('movie_observations')->select('movie_id', 'field')
            ->groupBy('movie_id', 'field')
            ->havingRaw('COUNT(DISTINCT value_hash) > 1');

        $byField = DB::query()->fromSub($conflicts(), 'conflicts')
            ->select('field')
            ->selectRaw('COUNT(*) AS conflicts')
            ->groupBy('field')
            ->orderBy('field')
            ->get()
            ->mapWithKeys(static fn(object $row): array => [(string) $row->field => (int) $row->conflicts])
            ->all();

        $moviesWithConflict = (int) DB::query()->fromSub($conflicts(), 'conflicts')
            ->distinct()
            ->count('movie_id');

        return ['movies_with_a_conflict' => $moviesWithConflict, 'by_field' => $byField];
    }
}

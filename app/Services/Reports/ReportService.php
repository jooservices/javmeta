<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Read-only numbers for the owner: data quality, crawl cost and source freshness. */
final class ReportService
{
    /** The 11 core movie fields of the product definition. */
    public const CORE_FIELDS = ['code', 'title_jp', 'title_en', 'actresses', 'cover_url', 'release_date', 'runtime', 'maker', 'label', 'series', 'genres'];

    public const PROJECTION_TITLES = [100_000, 400_000];

    /** @return array<string, mixed> */
    public function quality(): array
    {
        $filled = array_fill_keys(self::CORE_FIELDS, 0);
        $movies = 0;
        $atLeastEight = 0;
        $tiers = [];

        DB::table('movies')->select(['id', 'display_code', 'title_jp', 'title_en', 'cover_url', 'release_date', 'runtime_minutes', 'maker', 'label', 'series', 'completeness_tier'])
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$filled, &$movies, &$atLeastEight, &$tiers): void {
                $ids = $chunk->pluck('id')->all();
                $withActresses = DB::table('movie_performers')->whereIn('movie_id', $ids)->distinct()->pluck('movie_id')->flip()->all();
                $withGenres = DB::table('movie_genres')->whereIn('movie_id', $ids)->distinct()->pluck('movie_id')->flip()->all();

                foreach ($chunk as $movie) {
                    $present = [
                        'code' => $this->has($movie->display_code),
                        'title_jp' => $this->has($movie->title_jp),
                        'title_en' => $this->has($movie->title_en),
                        'actresses' => isset($withActresses[$movie->id]),
                        'cover_url' => $this->has($movie->cover_url),
                        'release_date' => $this->has($movie->release_date),
                        'runtime' => $movie->runtime_minutes !== null && (int) $movie->runtime_minutes > 0,
                        'maker' => $this->has($movie->maker),
                        'label' => $this->has($movie->label),
                        'series' => $this->has($movie->series),
                        'genres' => isset($withGenres[$movie->id]),
                    ];
                    $count = 0;
                    foreach ($present as $field => $isPresent) {
                        if ($isPresent) {
                            $filled[$field]++;
                            $count++;
                        }
                    }
                    $movies++;
                    $atLeastEight += $count >= 8 ? 1 : 0;
                    $tiers[(string) $movie->completeness_tier] = ($tiers[(string) $movie->completeness_tier] ?? 0) + 1;
                }
            });

        ksort($tiers);
        $fields = [];
        foreach ($filled as $field => $count) {
            $fields[$field] = ['filled' => $count, 'percent' => $this->percent($count, $movies)];
        }

        return [
            'movies' => $movies,
            'performers' => DB::table('performers')->count(),
            'movies_by_source' => DB::table('movie_observations')->select('source_slug', DB::raw('COUNT(DISTINCT movie_id) AS movies'))->groupBy('source_slug')->orderBy('source_slug')->pluck('movies', 'source_slug')->map(static fn($value): int => (int) $value)->all(),
            'core_fields' => $fields,
            'movies_with_8_of_11' => ['count' => $atLeastEight, 'percent' => $this->percent($atLeastEight, $movies)],
            'completeness_tiers' => $tiers,
            'field_conflicts' => $this->fieldConflicts($movies),
            'needs_review' => DB::table('movies')->where('needs_review', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function cost(): array
    {
        $runs = DB::table('crawl_runs')
            ->select('source_slug', DB::raw('SUM(pages_fetched) AS pages'), DB::raw('SUM(failures) AS failures'), DB::raw('SUM(proxy_requests) AS proxy_requests'), DB::raw('SUM(movies_new + movies_updated) AS movies'))
            ->groupBy('source_slug')->orderBy('source_slug')->get();
        $failuresByCode = DB::table('crawl_queue')->where('status', 'failed')->select('source_slug', 'error_code', DB::raw('COUNT(*) AS rows_failed'))
            ->groupBy('source_slug', 'error_code')->get();

        $wallSeconds = [];
        foreach (DB::table('crawl_runs')->whereNotNull('finished_at')->get(['source_slug', 'started_at', 'finished_at']) as $run) {
            $wallSeconds[$run->source_slug] = ($wallSeconds[$run->source_slug] ?? 0) + max(0, strtotime((string) $run->finished_at) - strtotime((string) $run->started_at));
        }

        $sources = [];
        foreach ($runs as $row) {
            $titles = max(0, (int) $row->movies);
            $pages = (int) $row->pages;
            $seconds = (int) ($wallSeconds[$row->source_slug] ?? 0);
            $projection = [];
            foreach (self::PROJECTION_TITLES as $target) {
                $projection[(string) $target] = $titles > 0
                    ? ['pages' => (int) round($pages / $titles * $target), 'hours' => round($seconds / $titles * $target / 3600, 1)]
                    : null;
            }
            $sources[$row->source_slug] = [
                'pages' => $pages,
                'titles' => $titles,
                'failures' => (int) $row->failures,
                'proxy_requests' => (int) $row->proxy_requests,
                'wall_seconds' => $seconds,
                'failed_rows_by_error_code' => $failuresByCode->where('source_slug', $row->source_slug)->mapWithKeys(static fn($item): array => [(string) ($item->error_code ?? 'unknown') => (int) $item->rows_failed])->all(),
                'projection' => $projection,
            ];
        }

        return [
            'formula' => 'projected = measured per title (pages, or wall seconds) x target titles, per source, from the finished crawl runs',
            'sources' => $sources,
        ];
    }

    /** @return array<string, mixed> */
    public function sources(): array
    {
        $health = $this->sourceHealthVerdicts();
        $rows = [];
        foreach (DB::table('sources')->orderBy('priority')->get(['slug', 'enabled', 'last_success_at', 'last_error_at', 'last_error']) as $source) {
            $last = $source->last_success_at !== null ? strtotime((string) $source->last_success_at) : null;
            $rows[$source->slug] = [
                'enabled' => (bool) $source->enabled,
                'last_success_at' => $source->last_success_at,
                'last_success_age_seconds' => $last !== null ? max(0, time() - $last) : null,
                'last_error' => $source->last_error,
                'health' => $health[$source->slug] ?? null,
            ];
        }

        return ['sources' => $rows];
    }

    /** @return array{movies_with_a_conflict: int, rate_percent: float, by_field: array<string, int>} by_field: field => number of movies where the sources gave different values */
    private function fieldConflicts(int $movies): array
    {
        $rows = DB::table('movie_observations')
            ->select('movie_id', 'field')
            ->groupBy('movie_id', 'field')
            ->havingRaw('COUNT(DISTINCT value_hash) > 1')
            ->get();
        $byField = [];
        foreach ($rows as $row) {
            $byField[$row->field] = ($byField[$row->field] ?? 0) + 1;
        }
        ksort($byField);

        return ['movies_with_a_conflict' => $rows->pluck('movie_id')->unique()->count(), 'rate_percent' => $this->percent($rows->pluck('movie_id')->unique()->count(), $movies)] + ['by_field' => $byField];
    }

    /** @return array<string, string> source slug => worst verdict from the last source health report */
    private function sourceHealthVerdicts(): array
    {
        $path = base_path('artifacts/reports/source-health.json');
        if (! File::exists($path)) {
            return [];
        }
        $report = json_decode((string) File::get($path), true);
        if (! is_array($report)) {
            return [];
        }
        $rank = ['WORKING' => 0, 'DISABLED' => 1, 'DEGRADED' => 2, 'BLOCKED' => 3];
        $worst = [];
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $slug = (string) ($row['source'] ?? '');
            $verdict = (string) ($row['verdict'] ?? '');
            if ($slug !== '' && (! isset($worst[$slug]) || ($rank[$verdict] ?? 0) > ($rank[$worst[$slug]] ?? 0))) {
                $worst[$slug] = $verdict;
            }
        }

        return $worst;
    }

    private function has(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function percent(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total * 100, 1);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Repositories\OwnerReportServiceRepository;
use Illuminate\Support\Facades\File;

/** Read-only numbers for the owner: data quality, crawl cost and source freshness. */
final class ReportService
{
    /** The 11 core movie fields of the product definition. */
    public const CORE_FIELDS = ['code', 'title_jp', 'title_en', 'actresses', 'cover_url', 'release_date', 'runtime', 'maker', 'label', 'series', 'genres'];

    public const PROJECTION_TITLES = [100_000, 400_000];

    public function __construct(private readonly OwnerReportServiceRepository $repository) {}

    /** @return array<string, mixed> */
    public function quality(): array
    {
        $filled = array_fill_keys(self::CORE_FIELDS, 0);
        $movies = 0;
        $atLeastEight = 0;
        $tiers = [];

        $this->repository->forEachQualityChunk(function (array $chunk) use (&$filled, &$movies, &$atLeastEight, &$tiers): void {
            foreach ($chunk as $movie) {
                $present = [
                    'code' => $this->has($movie['display_code']),
                    'title_jp' => $this->has($movie['title_jp']),
                    'title_en' => $this->has($movie['title_en']),
                    'actresses' => $movie['has_actresses'],
                    'cover_url' => $this->has($movie['cover_url']),
                    'release_date' => $this->has($movie['release_date']),
                    'runtime' => $movie['runtime_minutes'] !== null && (int) $movie['runtime_minutes'] > 0,
                    'maker' => $this->has($movie['maker']),
                    'label' => $this->has($movie['label']),
                    'series' => $this->has($movie['series']),
                    'genres' => $movie['has_genres'],
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
                $tiers[(string) $movie['completeness_tier']] = ($tiers[(string) $movie['completeness_tier']] ?? 0) + 1;
            }
        });

        ksort($tiers);
        $fields = [];
        foreach ($filled as $field => $count) {
            $fields[$field] = ['filled' => $count, 'percent' => $this->percent($count, $movies)];
        }

        return [
            'movies' => $movies,
            'performers' => $this->repository->performerCount(),
            'movies_by_source' => $this->repository->moviesBySource(),
            'core_fields' => $fields,
            'movies_with_8_of_11' => ['count' => $atLeastEight, 'percent' => $this->percent($atLeastEight, $movies)],
            'completeness_tiers' => $tiers,
            'field_conflicts' => $this->fieldConflicts($movies),
            'needs_review' => $this->repository->needsReviewCount(),
        ];
    }

    /** @return array<string, mixed> */
    public function cost(): array
    {
        $runs = $this->repository->crawlRunTotals();
        $failuresByCode = $this->repository->failedQueueCounts();

        $wallSeconds = [];
        foreach ($this->repository->finishedCrawlRuns() as $run) {
            $wallSeconds[$run['source_slug']] = ($wallSeconds[$run['source_slug']] ?? 0) + max(0, strtotime($run['finished_at']) - strtotime($run['started_at']));
        }

        $sources = [];
        foreach ($runs as $row) {
            $titles = max(0, $row['movies']);
            $pages = $row['pages'];
            $seconds = (int) ($wallSeconds[$row['source_slug']] ?? 0);
            $projection = [];
            foreach (self::PROJECTION_TITLES as $target) {
                $projection[(string) $target] = $titles > 0
                    ? ['pages' => (int) round($pages / $titles * $target), 'hours' => round($seconds / $titles * $target / 3600, 1)]
                    : null;
            }
            $sourceFailures = array_filter($failuresByCode, static fn(array $item): bool => $item['source_slug'] === $row['source_slug']);
            $failuresByErrorCode = [];
            foreach ($sourceFailures as $item) {
                $failuresByErrorCode[$item['error_code'] ?? 'unknown'] = $item['rows_failed'];
            }
            $sources[$row['source_slug']] = [
                'pages' => $pages,
                'titles' => $titles,
                'failures' => $row['failures'],
                'proxy_requests' => $row['proxy_requests'],
                'wall_seconds' => $seconds,
                'failed_rows_by_error_code' => $failuresByErrorCode,
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
        foreach ($this->repository->sourceStatusRows() as $source) {
            $last = $source['last_success_at'] !== null ? strtotime($source['last_success_at']) : null;
            $rows[$source['slug']] = [
                'enabled' => $source['enabled'],
                'last_success_at' => $source['last_success_at'],
                'last_success_age_seconds' => $last !== null ? max(0, time() - $last) : null,
                'last_error' => $source['last_error'],
                'health' => $health[$source['slug']] ?? null,
            ];
        }

        return ['sources' => $rows];
    }

    /** @return array{movies_with_a_conflict: int, rate_percent: float, by_field: array<string, int>} by_field: field => number of movies where the sources gave different values */
    private function fieldConflicts(int $movies): array
    {
        $byField = [];
        $movieIds = [];
        $rows = $this->repository->movieFieldConflicts();
        foreach ($rows as $row) {
            $byField[$row['field']] = ($byField[$row['field']] ?? 0) + 1;
            $movieIds[$row['movie_id']] = true;
        }
        ksort($byField);

        $conflictMovies = count($movieIds);

        return ['movies_with_a_conflict' => $conflictMovies, 'rate_percent' => $this->percent($conflictMovies, $movies)] + ['by_field' => $byField];
    }

    /** @return array<string, string> source slug => worst verdict from the last source health report */
    private function sourceHealthVerdicts(): array
    {
        $path = storage_path('app/private/reports/source-health.json');
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

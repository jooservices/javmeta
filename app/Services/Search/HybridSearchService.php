<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Movie;
use App\Models\Performer;
use App\Repositories\SearchDeadlineRepository;
use App\Repositories\PerformerRepository;
use Illuminate\Support\Collection;

/**
 * One search over movies and performers that combines the keyword search (with the filters read from the question) and the
 * vector search, merged by reciprocal rank fusion. When the vectors or Elasticsearch are unavailable the semantic part is
 * the keyword search again, so the answer is still good, just without the meaning.
 */
final class HybridSearchService
{
    private const RRF_K = 60;

    public function __construct(
        private readonly QueryInterpreter $interpreter,
        private readonly MovieSearchService $movies,
        private readonly PerformerSearchService $performerSearch,
        private readonly PerformerRepository $performers,
        private readonly SearchBudget $budget,
        private readonly ElasticsearchIndexer $indexer,
        private readonly SearchDeadlineRepository $searchDeadline,
    ) {}

    /**
     * @return array{movies: Collection<int, Movie>, performers: Collection<int, Performer>, interpretation: array<string, mixed>, totals: array{movies: int, performers: int}}
     */
    public function search(string $question, int $perPage = 12, bool $understand = true): array
    {
        return $this->budget->run(fn(): array => $this->searchWithinBudget($question, $perPage, $understand));
    }

    /** @return array{movies: Collection<int, Movie>, performers: Collection<int, Performer>, interpretation: array<string, mixed>, totals: array{movies: int, performers: int}} */
    private function searchWithinBudget(string $question, int $perPage, bool $understand): array
    {
        $question = trim($question);
        $started = hrtime(true);
        try {
            $interpretation = $understand ? $this->interpreter->interpret($question) : ['keywords' => $question, 'filters' => [], 'by' => 'none'];
        } catch (SearchDeadlineExceeded) {
            $this->budget->degrade('interpretation_timeout');
            $interpretation = ['keywords' => $question, 'filters' => [], 'by' => 'rules'];
        }
        $interpretation['timings_ms']['interpret'] = $this->elapsedMilliseconds($started);
        $filters = $interpretation['filters'];
        $text = $interpretation['keywords'] !== '' ? $interpretation['keywords'] : ($filters === [] ? $question : '');
        $pool = min(60, max($perPage * 3, $perPage));

        $hasText = mb_strlen($text) >= 2;
        $keywordMovies = collect();
        /** @var Collection<int, Performer> $performerKeyword */
        $performerKeyword = collect();
        $keywordStarted = hrtime(true);
        $keywordWindow = max(0, $this->budget->remainingMilliseconds() - 2500);
        try {
            $this->budget->within($keywordWindow, function () use (&$keywordMovies, &$performerKeyword, $filters, $hasText, $text, $pool, &$interpretation): void {
                $keywordMovies = $this->keywordMovies($filters, $hasText ? $text : null, $pool, $interpretation);
                if ($hasText) {
                    $performerKeyword = $this->performers->search(['q' => $text], 'name', null, $pool)['items'];
                }
            });
        } catch (SearchDeadlineExceeded) {
            $this->budget->degrade('keyword_timeout');
        }
        $interpretation['timings_ms']['keyword'] = $this->elapsedMilliseconds($keywordStarted);
        /** @var Collection<int, Movie> $keyword */
        $keyword = $keywordMovies->filter(fn(Movie $movie): bool => $this->hasTitle($movie))->values();
        $performerLimit = max(4, (int) ceil($perPage / 2));
        $needPerformers = $performerKeyword->count() < $performerLimit;
        $semanticResult = $this->semanticResults($question, $pool, $needPerformers, $filters);
        $vectors = $semanticResult['vectors'];
        $semantic = $semanticResult['movies'];
        $performerSemantic = $semanticResult['performers'];
        $interpretation['timings_ms']['semantic'] = $semanticResult['timing_ms'];

        $totalsStarted = hrtime(true);
        $movieTotal = 0;
        $performerTotal = 0;
        try {
            $this->searchDeadline->run(function () use (&$movieTotal, &$performerTotal, $filters, $hasText, $text, $keyword): void {
                $countFilters = $filters + ($hasText ? ['q' => $text] : []);
                $movieTotal = $countFilters === [] ? 0 : $this->movies->count($countFilters);
                if ($movieTotal === 0 && $hasText && $keyword->isNotEmpty()) {
                    $movieTotal = $this->movies->count(['q' => $text]);
                }
                $performerTotal = $hasText ? $this->performers->search(['q' => $text], 'name', null, 1)['total'] : 0;
            });
        } catch (SearchDeadlineExceeded) {
            $this->budget->degrade('totals_timeout');
        }
        $interpretation['timings_ms']['totals'] = $this->elapsedMilliseconds($totalsStarted);
        $interpretation['timings_ms']['budget_expired'] = $this->budget->expired();
        $interpretation['degraded'] = $this->budget->degraded();

        return [
            'movies' => $this->fuse([$keyword, $semantic])->take($perPage)->values(),
            'performers' => $this->fuse([$performerKeyword, $performerSemantic])->take($performerLimit)->values(),
            'interpretation' => $interpretation + ['used_vectors' => $vectors !== null],
            'totals' => [
                'movies' => $movieTotal,
                'performers' => $performerTotal,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{vectors: array{movies: list<string>, performers: list<string>}|null, movies: Collection<int, Movie>, performers: Collection<int, Performer>, timing_ms: int}
     */
    private function semanticResults(string $question, int $pool, bool $needPerformers, array $filters): array
    {
        $vectors = null;
        $started = hrtime(true);
        if (! $this->budget->expired() && mb_strlen($question) >= 2
            && ! app()->environment('testing') && (bool) config('elasticsearch.enabled', true)) {
            try {
                $vectors = $this->indexer->semanticCatalogUuids($question, min(100, $pool * 5), $needPerformers);
            } catch (SearchDeadlineExceeded) {
                $this->budget->degrade('semantic_timeout');
                $vectors = ['movies' => [], 'performers' => []];
            }
        }

        $movies = collect();
        if ($vectors !== null && ! $this->budget->expired()) {
            try {
                $movies = $this->movies->semanticSearch($question, $pool, $vectors['movies'])['items'];
            } catch (SearchDeadlineExceeded) {
                $this->budget->degrade('semantic_timeout');
            }
        }
        $movies = $movies->filter(fn(Movie $movie): bool => $this->hasTitle($movie))->values();
        if ($filters !== []) {
            $movies = $movies->filter(fn(Movie $movie): bool => $this->passes($movie, $filters))->values();
        }

        $performers = collect();
        if ($needPerformers && $vectors !== null && ! $this->budget->expired()) {
            try {
                $performers = $this->performerSearch->semanticSearch($question, $pool, $vectors['performers'])['items'];
            } catch (SearchDeadlineExceeded) {
                $this->budget->degrade('semantic_timeout');
            }
        }

        return [
            'vectors' => $vectors,
            'movies' => $movies,
            'performers' => $performers,
            'timing_ms' => $this->elapsedMilliseconds($started),
        ];
    }

    /**
     * Filters and words together first; when nothing matches, the filters alone, then the words alone, so a question that
     * over-specifies still finds the closest movies. What was relaxed is reported in the interpretation.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{keywords: string, filters: array<string, mixed>, by: string}  $interpretation
     * @return Collection<int, Movie>
     */
    private function keywordMovies(array $filters, ?string $text, int $pool, array &$interpretation): Collection
    {
        $attempts = [];
        if ($filters !== [] || $text !== null) {
            $attempts[] = ['both', $filters + ($text !== null ? ['q' => $text] : [])];
        }
        if ($filters !== [] && $text !== null) {
            $attempts[] = ['filters only', $filters];
            $attempts[] = ['words only', ['q' => $text]];
        }
        foreach ($attempts as [$label, $query]) {
            $items = $this->movies->search($query, isset($query['q']) ? 'relevance' : 'release_date', null, $pool)['items'];
            if ($items->isNotEmpty()) {
                if ($label !== 'both') {
                    $interpretation['relaxed'] = $label;
                }

                return $items;
            }
        }

        return collect();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  list<Collection<int, T>>  $lists
     * @return Collection<int, T>
     */
    private function fuse(array $lists): Collection
    {
        $score = [];
        $items = [];
        foreach ($lists as $list) {
            foreach ($list->values() as $rank => $item) {
                $key = $item->getKey();
                $score[$key] = ($score[$key] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
                $items[$key] ??= $item;
            }
        }
        arsort($score);

        return collect(array_map(static fn($key) => $items[$key], array_keys($score)));
    }

    /** @param array<string, mixed> $f */
    private function passes(Movie $m, array $f): bool
    {
        $eq = static fn(mixed $a, mixed $b): bool => is_string($a) && is_string($b) && mb_strtolower($a) === mb_strtolower($b);
        foreach (['maker', 'series', 'label'] as $field) {
            if (isset($f[$field]) && ! $eq($m->{$field}, $f[$field])) {
                return false;
            }
        }
        if (isset($f['genre'])) {
            $wantedGenres = is_array($f['genre']) ? $f['genre'] : [$f['genre']];
            $hasGenre = false;
            foreach ($wantedGenres as $wantedGenre) {
                $hasGenre = $hasGenre || (is_string($wantedGenre) && $m->genres->contains(fn($g): bool => $eq($g->label_raw ?? $g->label_normalized, $wantedGenre) || $eq($g->label_normalized, $wantedGenre)));
            }
            if (! $hasGenre) {
                return false;
            }
        }
        if (isset($f['actress']) && ! $m->performers->contains(fn($p): bool => $eq($p->name_romaji, $f['actress']) || $eq($p->name_kanji, $f['actress']))) {
            return false;
        }
        $released = $m->getAttribute('release_date');
        $date = $released instanceof \DateTimeInterface ? $released->format('Y-m-d') : ($released === null ? null : substr((string) $released, 0, 10));
        if ((isset($f['released_from']) || isset($f['released_to'])) && ($date === null || (isset($f['released_from']) && $date < $f['released_from']) || (isset($f['released_to']) && $date > $f['released_to']))) {
            return false;
        }
        if ((isset($f['runtime_min']) && ($m->runtime_minutes ?? 0) < $f['runtime_min']) || (isset($f['runtime_max']) && ($m->runtime_minutes === null || $m->runtime_minutes > $f['runtime_max']))) {
            return false;
        }

        return ! isset($f['code']) || $m->display_code === $f['code'];
    }

    private function hasTitle(Movie $movie): bool
    {
        return trim((string) $movie->title_en) !== '' || trim((string) $movie->title_jp) !== '';
    }

    private function elapsedMilliseconds(int $started): int
    {
        return max(0, (int) round((hrtime(true) - $started) / 1_000_000));
    }
}

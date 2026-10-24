<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Movie;
use App\Models\Performer;
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
        private readonly \App\Services\Dependencies\DependencyMonitor $dependencies,
    ) {}

    /**
     * @return array{movies: Collection<int, Movie>, performers: Collection<int, Performer>, interpretation: array<string, mixed>}
     */
    public function search(string $question, int $perPage = 12, bool $understand = true): array
    {
        return $this->budget->run(fn(): array => $this->searchWithinBudget($question, $perPage, $understand));
    }

    /** @return array{movies: Collection<int, Movie>, performers: Collection<int, Performer>, interpretation: array<string, mixed>} */
    private function searchWithinBudget(string $question, int $perPage, bool $understand): array
    {
        $question = trim($question);
        $interpretation = $understand ? $this->interpreter->interpret($question) : ['keywords' => $question, 'filters' => [], 'by' => 'none'];
        $filters = $interpretation['filters'];
        $text = $interpretation['keywords'] !== '' ? $interpretation['keywords'] : ($filters === [] ? $question : '');
        $pool = min(60, max($perPage * 3, $perPage));

        $hasText = mb_strlen($text) >= 2;
        $keyword = $this->keywordMovies($filters, $hasText ? $text : null, $pool, $interpretation);
        /** @var Collection<int, Performer> $performerKeyword */
        $performerKeyword = $hasText ? $this->performers->search(['q' => $text], 'name', null, $pool)['items'] : collect();
        $performerLimit = max(4, (int) ceil($perPage / 2));
        $needPerformers = $performerKeyword->count() < $performerLimit;
        $vectors = null;
        if (! $this->budget->expired() && mb_strlen($question) >= 2
            && ! app()->environment('testing') && (bool) config('elasticsearch.enabled', true)
            && $this->dependencies->isAvailable(\App\Services\Dependencies\Dependency::Elasticsearch)
            && $this->dependencies->isAvailable(\App\Services\Dependencies\Dependency::Embedder)) {
            $vectors = $this->indexer->semanticCatalogUuids($question, min(100, $pool * 5), $needPerformers);
        }
        /** @var Collection<int, Movie> $semantic */
        $semantic = ($vectors !== null || ! $this->budget->expired()) && mb_strlen($question) >= 2 ? $this->movies->semanticSearch($question, $pool, $vectors['movies'] ?? null)['items'] : collect();
        if ($filters !== []) {
            $semantic = $semantic->filter(fn(Movie $m): bool => $this->passes($m, $filters))->values();
        }

        // Only the words left after the filters were read: the whole sentence makes the name search slow and finds nothing.
        /** @var Collection<int, Performer> $performerSemantic */
        $performerSemantic = $needPerformers && ($vectors !== null || ! $this->budget->expired()) && mb_strlen($question) >= 2 ? $this->performerSearch->semanticSearch($question, $pool, $vectors['performers'] ?? null)['items'] : collect();

        return [
            'movies' => $this->fuse([$keyword, $semantic])->take($perPage)->values(),
            'performers' => $this->fuse([$performerKeyword, $performerSemantic])->take($performerLimit)->values(),
            'interpretation' => $interpretation,
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
        if (isset($f['genre']) && ! $m->genres->contains(fn($g): bool => $eq($g->label_raw ?? $g->label_normalized, $f['genre']) || $eq($g->label_normalized, $f['genre']))) {
            return false;
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
}

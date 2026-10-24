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
    ) {}

    /**
     * @return array{movies: Collection<int, Movie>, performers: Collection<int, Performer>, interpretation: array{keywords: string, filters: array<string, mixed>, by: string}}
     */
    public function search(string $question, int $perPage = 12, bool $understand = true): array
    {
        $question = trim($question);
        $interpretation = $understand ? $this->interpreter->interpret($question) : ['keywords' => $question, 'filters' => [], 'by' => 'none'];
        $filters = $interpretation['filters'];
        $text = $interpretation['keywords'] !== '' ? $interpretation['keywords'] : ($filters === [] ? $question : '');
        $pool = min(60, max($perPage * 3, $perPage));

        $keywordFilters = $filters + (mb_strlen($text) >= 2 ? ['q' => $text] : []);
        $keyword = $keywordFilters === [] ? collect() : $this->movies->search($keywordFilters, mb_strlen($text) >= 2 ? 'relevance' : 'release_date', null, $pool)['items'];
        $semantic = mb_strlen($question) >= 2 ? $this->movies->semanticSearch($question, $pool)['items'] : collect();
        if ($filters !== []) {
            $semantic = $semantic->filter(fn(Movie $m): bool => $this->passes($m, $filters))->values();
        }

        $performerKeyword = mb_strlen($question) >= 2 ? $this->performers->search(['q' => $question], 'name', null, $pool)['items'] : collect();
        $performerSemantic = mb_strlen($question) >= 2 ? $this->performerSearch->semanticSearch($question, $pool)['items'] : collect();

        return [
            'movies' => $this->fuse([$keyword, $semantic])->take($perPage)->values(),
            'performers' => $this->fuse([$performerKeyword, $performerSemantic])->take(max(4, (int) ceil($perPage / 2)))->values(),
            'interpretation' => $interpretation,
        ];
    }

    /**
     * @param  list<Collection<int, \Illuminate\Database\Eloquent\Model>>  $lists
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
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
        $released = $m->release_date;
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

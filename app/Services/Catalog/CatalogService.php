<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Data\Catalog\CatalogQuery;
use App\Data\Catalog\CatalogSnapshot;
use App\Repositories\MovieRepository;
use App\Repositories\PerformerRepository;
use App\Services\Normalize\PerformerAttributeNormalizer;
use App\Support\Catalog\NameScript;
use Illuminate\Support\Facades\Cache;

/** Reads and caches catalog facets for movies and performers. */
final class CatalogService
{
    /** @var array<string, string> */
    private const PERFORMER_COLUMNS = ['cups' => 'cup', 'blood_types' => 'blood_type', 'locations' => 'location'];

    public function __construct(
        private readonly MovieRepository $movies,
        private readonly PerformerRepository $performers,
        private readonly PerformerAttributeNormalizer $normalizer,
    ) {}

    public function index(CatalogQuery $query): CatalogSnapshot
    {
        if ($this->readsWholeCatalog($query)) {
            // Every page of this catalog needs all of it (the writing system or the grouping is decided in PHP), so the
            // whole ordered catalog is cached once, independently of the page, and each response slices it.
            $wholeKey = 'javmeta:catalog:whole:' . hash('sha256', json_encode([$query->kind, $query->query, $query->sort, $query->includeUnknown, $query->minCount, $query->script], JSON_THROW_ON_ERROR));
            /** @var array{items: list<array<string, mixed>>, hidden: int} $whole */
            $whole = Cache::remember($wholeKey, 120, fn(): array => $this->loadWhole($query));
            $result = [
                'items' => array_slice($whole['items'], ($query->page - 1) * $query->perPage, $query->perPage),
                'total' => count($whole['items']),
                'hidden' => $whole['hidden'],
            ];
        } else {
            $key = 'javmeta:catalog:' . hash('sha256', json_encode([$query->kind, $query->query, $query->sort, $query->perPage, $query->page, $query->includeUnknown, $query->minCount, $query->script], JSON_THROW_ON_ERROR));
            /** @var array{items: list<array<string, mixed>>, total: int, hidden: int} $result */
            $result = Cache::remember($key, 120, fn(): array => $this->load($query));
        }

        return new CatalogSnapshot($result['items'], [
            'total' => $result['total'],
            'per_page' => $query->perPage,
            'page' => $query->page,
            'last_page' => max(1, (int) ceil($result['total'] / $query->perPage)),
            'sort' => $query->sort,
            'min_count' => $query->minCount,
            'hidden_below_min' => $result['hidden'],
            'script' => $query->script,
        ]);
    }

    private function readsWholeCatalog(CatalogQuery $query): bool
    {
        return isset(self::PERFORMER_COLUMNS[$query->kind]) || $query->script !== 'all';
    }

    /** @return array{items: list<array<string, mixed>>, hidden: int} */
    private function loadWhole(CatalogQuery $query): array
    {
        if (isset(self::PERFORMER_COLUMNS[$query->kind])) {
            return $this->loadPerformers(self::PERFORMER_COLUMNS[$query->kind], $query);
        }

        return $this->movies->movieCatalogWhole($query->kind, $query->query, $query->sort, $query->minCount, $query->script);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, hidden: int} */
    private function load(CatalogQuery $query): array
    {
        return $this->movies->movieCatalog($query->kind, $query->query, $query->sort, $query->perPage, $query->page, $query->minCount, $query->script);
    }

    /** @return array{items: list<array{name: string, performer_count: int}>, hidden: int} */
    private function loadPerformers(string $column, CatalogQuery $query): array
    {
        $groups = $this->performers->facetGroups($column);
        $counts = [];
        $matchesQuery = [];
        $unknownCount = 0;
        foreach ($groups as $group) {
            $rawName = (string) $group->raw_name;
            $name = $this->normalizer->facetValue($column, $rawName);
            if ($name === null) {
                if ($column === 'blood_type') {
                    $unknownCount += (int) $group->performer_count;
                }
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + (int) $group->performer_count;
            $matchesQuery[$name] = ($matchesQuery[$name] ?? false)
                || $query->query === '' || stripos($name, $query->query) !== false || stripos($rawName, $query->query) !== false;
        }
        if ($column === 'blood_type' && $query->includeUnknown) {
            $unknownCount += $this->performers->unknownBloodTypeCount();
            if ($query->query === '' || stripos('Unknown', $query->query) !== false) {
                $counts['Unknown'] = $unknownCount;
                $matchesQuery['Unknown'] = true;
            }
        }

        $items = [];
        foreach ($counts as $name => $count) {
            if (! $matchesQuery[$name] || ! NameScript::matches((string) $name, $query->script)) {
                continue;
            }
            $items[] = ['name' => $name, 'performer_count' => $count];
        }
        if ($query->sort === 'name') {
            usort($items, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));
        } else {
            usort($items, static fn(array $left, array $right): int => $right['performer_count'] <=> $left['performer_count'] ?: strcmp($left['name'], $right['name']));
        }
        $named = count($items);
        $items = array_values(array_filter($items, static fn(array $item): bool => $item['performer_count'] >= $query->minCount));

        return ['items' => $items, 'hidden' => $named - count($items)];
    }
}

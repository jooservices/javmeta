<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Data\Catalog\CatalogQuery;
use App\Data\Catalog\CatalogSnapshot;
use App\Repositories\MovieCatalogRepository;
use App\Repositories\PerformerCatalogRepository;
use App\Services\Normalize\PerformerAttributeNormalizer;
use Illuminate\Support\Facades\Cache;

/** Reads and caches catalog facets for movies and performers. */
final class CatalogService
{
    /** @var array<string, string> */
    private const PERFORMER_COLUMNS = ['cups' => 'cup', 'blood_types' => 'blood_type', 'locations' => 'location'];

    public function __construct(
        private readonly MovieCatalogRepository $movies,
        private readonly PerformerCatalogRepository $performers,
        private readonly PerformerAttributeNormalizer $normalizer,
    ) {}

    public function index(CatalogQuery $query): CatalogSnapshot
    {
        $key = 'javmeta:catalog:' . md5(json_encode([$query->kind, $query->query, $query->sort, $query->perPage, $query->page, $query->includeUnknown], JSON_THROW_ON_ERROR));
        /** @var array{items: list<array<string, mixed>>, total: int} $result */
        $result = Cache::remember($key, 120, fn(): array => $this->load($query));

        return new CatalogSnapshot($result['items'], [
            'total' => $result['total'],
            'per_page' => $query->perPage,
            'page' => $query->page,
            'last_page' => max(1, (int) ceil($result['total'] / $query->perPage)),
            'sort' => $query->sort,
        ]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function load(CatalogQuery $query): array
    {
        if (isset(self::PERFORMER_COLUMNS[$query->kind])) {
            return $this->loadPerformers(self::PERFORMER_COLUMNS[$query->kind], $query);
        }

        return $this->movies->movieCatalog($query->kind, $query->query, $query->sort, $query->perPage, $query->page);
    }

    /** @return array{items: list<array{name: string, performer_count: int}>, total: int} */
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
            if (! $matchesQuery[$name]) {
                continue;
            }
            $items[] = ['name' => $name, 'performer_count' => $count];
        }
        if ($query->sort === 'name') {
            usort($items, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));
        } else {
            usort($items, static fn(array $left, array $right): int => $right['performer_count'] <=> $left['performer_count'] ?: strcmp($left['name'], $right['name']));
        }
        $total = count($items);
        $items = array_slice($items, ($query->page - 1) * $query->perPage, $query->perPage);

        return ['items' => $items, 'total' => $total];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Performer;
use App\Repositories\PerformerRepository;
use Illuminate\Support\Collection;

/**
 * Semantic performer search: Elasticsearch UUIDs → hydrated PostgreSQL rows.
 * Falls back to the repository keyword search when semantic dependencies are unavailable.
 */
final class PerformerSearchService
{
    public function __construct(
        private readonly ElasticsearchIndexer $elasticsearch,
        private readonly PerformerRepository $performers,
    ) {}

    /**
     * @return array{items: Collection<int, Performer>, total: int, has_more: bool, last_id: int|null, last_sort: mixed, sort: string}
     */
    public function semanticSearch(string $query, int $perPage): array
    {
        if (app()->environment('testing')) {
            return $this->performers->search(['q' => $query], 'name', null, $perPage);
        }

        $uuids = $this->elasticsearch->semanticPerformerUuids(
            $query,
            min(100, max($perPage * 5, $perPage)),
        );

        if ($uuids !== []) {
            $resolved = $this->performers->findByUuidsForDisplay($uuids);
            $ordered = $resolved->take($perPage)->values();

            if ($ordered->isNotEmpty()) {
                /** @var Performer $last */
                $last = $ordered->last();

                return [
                    'items' => $ordered,
                    'total' => $resolved->count(),
                    'has_more' => $resolved->count() > $perPage,
                    'last_id' => (int) $last->id,
                    'last_sort' => null,
                    'sort' => 'relevance',
                ];
            }
        }

        return $this->performers->search(['q' => $query], 'name', null, $perPage);
    }
}

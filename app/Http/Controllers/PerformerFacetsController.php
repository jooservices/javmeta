<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PerformerFacetListRequest;
use App\Http\Requests\PerformerSearchRequest;
use App\Repositories\PerformerRepository;
use App\Services\Facets\FacetListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** What the performers of a filter have in common (cups, blood types, locations, birth decades, sources, status) with counts. */
final class PerformerFacetsController extends BaseApiController
{
    public function index(PerformerSearchRequest $request, PerformerRepository $performers): JsonResponse
    {
        $filters = $request->validated();
        $filters['include_unknown'] = $request->boolean('include_unknown');
        unset($filters['sort'], $filters['cursor'], $filters['page'], $filters['per_page']);
        $data = Cache::remember('javmeta:pfacets:' . hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)), 60, fn(): array => $performers->facets($filters));

        return $this->respondWithData(data: $data, message: 'Facets retrieved successfully.');
    }

    /** One searchable, paginated facet value list (guest-readable). */
    public function values(PerformerFacetListRequest $request, FacetListService $facets): JsonResponse
    {
        $query = $request->facetListQuery();
        $data = $facets->performerValues($query);
        $lastPage = max(1, (int) ceil($data['total'] / $query->perPage));

        return $this->respondWithData(
            data: $data,
            message: 'Facet values retrieved successfully.',
            meta: [
                'pagination' => [
                    'total' => $data['total'],
                    'per_page' => $query->perPage,
                    'page' => $query->page,
                    'last_page' => $lastPage,
                    'sort' => $query->sort,
                    'min_count' => $query->minCount,
                ],
            ],
        );
    }
}

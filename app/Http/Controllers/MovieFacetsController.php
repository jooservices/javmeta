<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MovieFacetListRequest;
use App\Http\Requests\MovieSearchRequest;
use App\Services\Facets\FacetListService;
use App\Services\Performance\PerformanceCache;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** What the movies of a filter have in common (genres, makers, series, labels, years, sources, performers) with counts. */
final class MovieFacetsController extends BaseApiController
{
    public function index(MovieSearchRequest $request, PerformanceCache $cache): JsonResponse
    {
        $filters = $request->filters();
        $data = $cache->facetsForResponse($filters);

        return $this->respondWithData(data: $data, message: 'Facets retrieved successfully.');
    }

    /** One searchable, paginated facet value list (guest-readable). */
    public function values(MovieFacetListRequest $request, FacetListService $facets): JsonResponse
    {
        $query = $request->facetListQuery();
        $data = $facets->movieValues($query);
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

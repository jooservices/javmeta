<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PerformerSearchRequest;
use App\Repositories\PerformerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** What the performers of a filter have in common (cups, blood types, locations, birth decades, sources, status) with counts. */
final class PerformerFacetsController extends BaseApiController
{
    public function index(PerformerSearchRequest $request, PerformerRepository $performers): JsonResponse
    {
        $filters = $request->validated();
        unset($filters['sort'], $filters['cursor'], $filters['page'], $filters['per_page']);
        $data = Cache::remember('javmeta:pfacets:' . md5(json_encode($filters, JSON_THROW_ON_ERROR)), 60, fn(): array => $performers->facets($filters));

        return $this->respondWithData(data: $data, message: 'Facets retrieved successfully.');
    }
}

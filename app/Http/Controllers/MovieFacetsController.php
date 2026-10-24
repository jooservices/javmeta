<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MovieSearchRequest;
use App\Repositories\MovieRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** What the movies of a filter have in common (genres, makers, series, labels, years, sources, performers) with counts. */
final class MovieFacetsController extends BaseApiController
{
    public function index(MovieSearchRequest $request, MovieRepository $movies): JsonResponse
    {
        $filters = $request->filters();
        $data = Cache::remember('javmeta:facets:' . md5(json_encode($filters, JSON_THROW_ON_ERROR)), 60, fn(): array => $movies->facets($filters));

        return $this->respondWithData(data: $data, message: 'Facets retrieved successfully.');
    }
}

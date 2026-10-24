<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\InvalidFilterException;
use App\Http\Resources\MovieSummaryResource;
use App\Http\Resources\PerformerSummaryResource;
use App\Services\Search\HybridSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** One search box for everything: movies and performers, keywords, filters read from the question, and meaning. */
final class SearchController extends BaseApiController
{
    public function index(Request $request, HybridSearchService $search): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 12);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 300 || $perPage < 1 || $perPage > 50) {
            throw new InvalidFilterException('Invalid q or per_page.', ['q' => ['2 to 300 characters'], 'per_page' => ['1 to 50']]);
        }

        $result = $search->search($q, $perPage, ! in_array((string) $request->query('understand', '1'), ['0', 'false'], true));

        return $this->respondWithData(
            data: [
                'movies' => MovieSummaryResource::collection($result['movies'])->resolve($request),
                'performers' => PerformerSummaryResource::collection($result['performers'])->resolve($request),
                'interpretation' => $result['interpretation'],
            ],
            message: 'Search completed successfully.',
        );
    }
}

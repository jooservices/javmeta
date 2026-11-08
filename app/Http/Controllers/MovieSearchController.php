<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\InvalidFilterException;
use App\Http\Requests\MovieSearchRequest;
use App\Http\Resources\MovieSummaryResource;
use App\Services\Search\CursorCodec;
use App\Services\Search\MovieSearchService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class MovieSearchController extends BaseApiController
{
    public function index(MovieSearchRequest $request, MovieSearchService $search, CursorCodec $codec): JsonResponse
    {
        $perPage = (int) $request->validated('per_page', 10);
        $semantic = $request->boolean('semantic');
        $cursorParam = $semantic ? null : $request->validated('cursor');

        if ($semantic) {
            $result = $search->semanticSearch((string) $request->validated('q'), $perPage);
        } else {
            $sort = (string) $request->validated('sort', 'relevance');
            $cursor = null;

            if (is_string($cursorParam) && $cursorParam !== '') {
                $cursor = $codec->decode($cursorParam);

                // A cursor is only meaningful for the sort that produced it.
                $explicitSort = $request->validated('sort');
                if (is_string($explicitSort) && $explicitSort !== $cursor['sort']) {
                    throw new InvalidFilterException('The sort parameter cannot change between pages.');
                }

                $sort = $cursor['sort'];
            }

            $result = $search->search($request->filters(), $sort, $cursor, $perPage);
        }

        $nextCursor = null;
        if (! $semantic && $result['last_id'] !== null) {
            // Emit cursor for the last row even on the final page so a follow-up
            // request can return an empty page beyond total (AC-4.3).
            $nextCursor = $codec->encode($result['sort'], $result['last_sort'], $result['last_id']);
        }

        return $this->respondWithData(
            data: MovieSummaryResource::collection($result['items']),
            message: 'Movies retrieved successfully.',
            meta: [
                'pagination' => [
                    'total' => $result['total'],
                    'per_page' => $perPage,
                    'sort' => $result['sort'],
                    'has_more' => $result['has_more'],
                    'cursor' => ! $semantic && is_string($cursorParam) && $cursorParam !== '' ? $cursorParam : null,
                    'next_cursor' => $nextCursor,
                ],
            ],
        );
    }
}

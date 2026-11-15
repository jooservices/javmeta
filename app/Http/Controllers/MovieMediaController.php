<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Api\MovieMediaDto;
use App\Exceptions\MovieNotFoundException;
use App\Http\Requests\MovieMediaRequest;
use App\Services\Movies\MovieLookupService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class MovieMediaController extends BaseApiController
{
    public function __invoke(MovieMediaRequest $request, MovieLookupService $movies, string $code): JsonResponse
    {
        $kind = (string) $request->validated('kind');
        $result = $movies->mediaPageByCode(
            rawCode: $code,
            kind: $kind,
            perPage: (int) $request->validated('per_page', 50),
            page: (int) $request->validated('page', 1),
        );
        if ($result === null) {
            throw new MovieNotFoundException($code);
        }

        $data = array_map(static fn(MovieMediaDto $media): array => $media->toArray(), $result['items']);

        return $this->respondWithData(
            data: $data,
            message: 'Movie media retrieved successfully.',
            meta: [
                'total' => $result['total'],
                'pagination' => [
                    'total' => $result['total'],
                    'per_page' => $result['per_page'],
                    'current_page' => $result['page'],
                    'last_page' => $result['last_page'],
                    'has_more' => $result['has_more'],
                ],
            ],
        );
    }
}

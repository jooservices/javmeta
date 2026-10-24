<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MovieNotFoundException;
use App\Http\Resources\MovieSummaryResource;
use App\Models\Movie;
use App\Services\Movies\MovieLookupService;
use App\Services\Search\MovieSearchService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** Movies related to one movie: same performer, then same series, then same maker; the movie itself is never listed. */
final class MovieRelatedController extends BaseApiController
{
    private const LIMIT = 12;

    public function show(string $code, MovieLookupService $movies, MovieSearchService $search): JsonResponse
    {
        $movie = $movies->findByCode($code);
        if (! $movie instanceof Movie) {
            throw new MovieNotFoundException($code);
        }

        $filters = [];
        $performer = $movie->performers()->orderBy('performers.id')->first();
        $name = $performer?->name_romaji ?? $performer?->name_kanji;
        if (is_string($name) && $name !== '') {
            $filters[] = ['actress' => $name];
        }
        if ($movie->series) {
            $filters[] = ['series' => $movie->series];
        }
        if ($movie->maker) {
            $filters[] = ['maker' => $movie->maker];
        }

        $related = [];
        foreach ($filters as $filter) {
            $result = $search->search($filter, 'release_date', null, self::LIMIT + 1);
            foreach ($result['items'] as $item) {
                if ($item->id !== $movie->id && ! isset($related[$item->id])) {
                    $related[$item->id] = $item;
                }
            }
            if (count($related) >= self::LIMIT) {
                break;
            }
        }

        return $this->respondWithData(
            data: MovieSummaryResource::collection(collect(array_slice(array_values($related), 0, self::LIMIT))),
            message: 'Related movies retrieved successfully.',
        );
    }
}

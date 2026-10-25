<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MovieNotFoundException;
use App\Http\Resources\MovieSummaryResource;
use App\Models\Movie;
use App\Services\Movies\MovieLookupService;
use App\Services\Search\MovieSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/**
 * Movies related to one movie: same performer, then same series, then same maker; the movie itself is never listed.
 * The answer is cached for ten minutes because three searches make it slow.
 */
final class MovieRelatedController extends BaseApiController
{
    private const LIMIT = 12;

    public function show(string $code, MovieLookupService $movies, MovieSearchService $search): JsonResponse
    {
        $movie = $movies->findByCode($code);
        if (! $movie instanceof Movie) {
            throw new MovieNotFoundException($code);
        }

        $items = Cache::remember('javmeta:related:' . $movie->id, 600, fn(): array => $this->related($movie, $search));

        return $this->respondWithData(data: $items, message: 'Related movies retrieved successfully.');
    }

    /** @return list<array<string, mixed>> */
    private function related(Movie $movie, MovieSearchService $search): array
    {
        $filters = [];
        $performer = $movie->performers()->orderBy('performers.id')->first();
        $name = $performer->name_romaji ?? $performer->name_kanji ?? null;
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

        return MovieSummaryResource::collection(collect(array_slice(array_values($related), 0, self::LIMIT)))->resolve(Request::create('/'));
    }
}

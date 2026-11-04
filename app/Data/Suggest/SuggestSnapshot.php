<?php

declare(strict_types=1);

namespace App\Data\Suggest;

/** Suggestions grouped by their public result type. */
final readonly class SuggestSnapshot
{
    /**
     * @param list<array{code: string, title: ?string, maker: ?string}> $movies
     * @param list<array{id: int, name: ?string}> $performers
     * @param list<array{name: string, movie_count: int}> $genres
     * @param list<array{name: string, movie_count: int}> $makers
     * @param list<array{name: string, movie_count: int}> $series
     */
    public function __construct(
        public array $movies,
        public array $performers,
        public array $genres,
        public array $makers,
        public array $series,
    ) {}

    /** @return array{movies: list<array{code: string, title: ?string, maker: ?string}>, performers: list<array{id: int, name: ?string}>, genres: list<array{name: string, movie_count: int}>, makers: list<array{name: string, movie_count: int}>, series: list<array{name: string, movie_count: int}>} */
    public function toArray(): array
    {
        return [
            'movies' => $this->movies,
            'performers' => $this->performers,
            'genres' => $this->genres,
            'makers' => $this->makers,
            'series' => $this->series,
        ];
    }
}

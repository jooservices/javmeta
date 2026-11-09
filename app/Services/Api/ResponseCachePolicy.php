<?php

declare(strict_types=1);

namespace App\Services\Api;

final class ResponseCachePolicy
{
    /** @var list<string> */
    private const MOVIE_FILTERS = [
        'q', 'code', 'genre', 'actress', 'maker', 'series', 'label', 'released_from', 'released_to',
        'runtime_min', 'runtime_max', 'censored', 'on_disk', 'actress_id', 'genre_all', 'source',
        'score_min', 'tier_min', 'has_cover', 'has_magnet', 'has_performers',
    ];

    /** @var list<string> */
    private const PERFORMER_FILTERS = [
        'q', 'age_min', 'age_max', 'height_min', 'height_max', 'bust_min', 'bust_max', 'waist_min',
        'waist_max', 'hip_min', 'hip_max', 'cup', 'blood_type', 'location', 'has_image', 'has_bio',
        'status', 'movies_min', 'debut_from', 'debut_to', 'source',
    ];

    /** @var list<string> */
    private const LOCALE = ['lang'];

    /** @var list<string> */
    private const MOVIE_LIST = [
        ...self::MOVIE_FILTERS, 'sort', 'cursor', 'per_page', 'semantic', ...self::LOCALE,
    ];

    /** @var list<string> */
    private const PERFORMER_LIST = [
        ...self::PERFORMER_FILTERS, 'sort', 'cursor', 'page', 'per_page', ...self::LOCALE,
    ];

    /** @var array<string, ResponseCacheRule> */
    private array $rules;

    public function __construct()
    {
        $movieFacets = [...self::MOVIE_FILTERS, ...self::LOCALE];
        $performerFacets = [...self::PERFORMER_FILTERS, 'include_unknown', ...self::LOCALE];
        $movieFacetValues = ['facet', 'sort', 'page', 'per_page', 'min_count', 'keyword', ...self::MOVIE_FILTERS, ...self::LOCALE];
        $performerFacetValues = ['facet', 'sort', 'page', 'per_page', 'min_count', 'keyword', ...self::PERFORMER_FILTERS, 'include_unknown', ...self::LOCALE];
        $catalog = ['q', 'sort', 'per_page', 'page', 'include_unknown', 'min_count', 'script', ...self::LOCALE];

        $this->rules = [
            'api/v1/movies' => new ResponseCacheRule('lists', self::MOVIE_LIST, ['per_page' => 10, 'sort' => 'relevance', 'semantic' => false], 100),
            'api/v1/performers' => new ResponseCacheRule('lists', self::PERFORMER_LIST, ['per_page' => 15, 'sort' => 'name'], 100),
            'api/v1/movies/facets' => new ResponseCacheRule('facets', $movieFacets),
            'api/v1/performers/facets' => new ResponseCacheRule('facets', $performerFacets),
            'api/v1/movies/facets/values' => new ResponseCacheRule('facets', $movieFacetValues),
            'api/v1/performers/facets/values' => new ResponseCacheRule('facets', $performerFacetValues),
            'api/v1/meta/genres' => new ResponseCacheRule('catalog', self::LOCALE),
            'api/v1/meta/catalog/{kind}' => new ResponseCacheRule('catalog', $catalog, ['q' => '', 'per_page' => 50, 'page' => 1], 200),
            'api/v1/suggest' => new ResponseCacheRule('suggestions', ['q', 'limit', ...self::LOCALE], ['limit' => 5]),
            'api/v1/status/summary' => new ResponseCacheRule('status', self::LOCALE),
            'api/v1/movies/{code}' => new ResponseCacheRule('movie_detail', self::LOCALE),
        ];
    }

    public function forApiRoute(string $route): ?ResponseCacheRule
    {
        return $this->rules[$route] ?? null;
    }

    public function forUiPath(string $path): ?ResponseCacheRule
    {
        $route = match (true) {
            in_array($path, ['movies', 'performers', 'movies/facets', 'performers/facets', 'movies/facets/values', 'performers/facets/values', 'meta/genres', 'suggest', 'status/summary'], true) => 'api/v1/' . $path,
            preg_match('#^meta/catalog/[a-z_]+$#D', $path) === 1 => 'api/v1/meta/catalog/{kind}',
            preg_match('#^movies/[A-Za-z0-9._-]+$#D', $path) === 1 => 'api/v1/movies/{code}',
            default => null,
        };

        return $route === null ? null : $this->forApiRoute($route);
    }
}

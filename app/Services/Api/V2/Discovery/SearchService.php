<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Discovery;

use App\Repositories\Api\V2\SearchRepository;
use App\Services\Api\V2\Catalogue\CatalogueFilters;
use App\Services\Api\V2\Catalogue\Formatter;
use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Str;

/** Unified search, suggestions, the photo tab and catalogue-name matches. */
final class SearchService
{
    private const TYPES = ['movie', 'performer', 'photo'];

    public function __construct(private readonly SearchRepository $repository, private readonly Formatter $formatter) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function search(string $text, string $types, int $page, int $perPage): array
    {
        $wanted = array_intersect(self::TYPES, array_map('trim', explode(',', $types === '' ? implode(',', self::TYPES) : $types)));
        $offset = ($page - 1) * $perPage;

        $movies = in_array('movie', $wanted, true) ? $this->repository->movies($text, $perPage, $offset) : null;
        $performers = in_array('performer', $wanted, true) ? $this->repository->performers($text, $perPage, $offset) : null;
        $photos = in_array('photo', $wanted, true) ? $this->repository->photos($text, $perPage, $offset) : null;

        $found = array_filter([$movies, $performers, $photos], static fn(?array $group): bool => $group !== null && $group['total'] > 0);

        return [
            'data' => [
                'movies' => $movies === null ? null : ['rows' => array_map($this->formatter->movie(...), $movies['rows']), 'total' => $movies['total']],
                'performers' => $performers === null ? null : ['rows' => array_map($this->formatter->performer(...), $performers['rows']), 'total' => $performers['total']],
                'photos' => $photos === null ? null : ['rows' => array_map($this->avatar(...), $photos['rows']), 'total' => $photos['total']],
            ],
            'meta' => ['query' => $text, 'did_you_mean' => $found === [] ? $this->repository->closest($text) : null, 'looks_like_code' => preg_match('/^[a-z]{2,6}-?\d{2,5}$/i', $text) === 1],
        ];
    }

    /** @return array<string, mixed> */
    public function suggestions(string $text, int $limit): array
    {
        if (mb_strlen($text) < 2) {
            return ['movies' => [], 'performers' => [], 'makers' => [], 'series' => [], 'genres' => []];
        }

        $found = $this->repository->suggestions($text, min(10, max(1, $limit)));
        $named = static fn(object $row): array => ['name' => $row->name, 'count' => (int) $row->count, 'slug' => Str::slug((string) $row->name)];

        return [
            'movies' => array_map(static fn(object $row): array => ['code' => $row->code, 'title' => $row->title_en ?: $row->title_jp, 'cover_url' => $row->cover_thumb_url ?: $row->cover_url], $found['movies']),
            'performers' => array_map(static fn(object $row): array => ['id' => (int) $row->id, 'slug' => $row->id . '-' . Str::slug((string) $row->name_romaji), 'name' => $row->name_romaji, 'name_kanji' => $row->name_kanji, 'photo_url' => $row->photo_url, 'movie_count' => (int) $row->movie_count], $found['performers']),
            'makers' => array_map($named, $found['makers']),
            'series' => array_map($named, $found['series']),
            'genres' => array_map($named, $found['genres']),
        ];
    }

    /**
     * Covers of the matching movies and photographed performers of them (people only on the first page and when no performer filter is set).
     *
     * @param array<string, mixed> $query
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function photos(array $query, int $page, int $perPage): array
    {
        $covers = $this->repository->covers($query, $perPage, ($page - 1) * $perPage);
        $people = $page > 1 || $this->hasPerformerFilter($query) ? [] : $this->repository->cast($query, 24);

        return [
            'data' => [
                'total' => $covers['total'] + count($people),
                'people' => array_map(static fn(object $row): array => ['kind' => 'avatar', 'src' => $row->photo_url, 'full' => $row->photo_url, 'to' => '/performers/' . $row->id . '-' . Str::slug((string) $row->name_romaji), 'label' => $row->name_romaji, 'performer_id' => (int) $row->id], $people),
                'covers' => array_map(static fn(object $row): array => ['kind' => 'cover', 'src' => $row->cover_thumb_url ?: $row->cover_url, 'full' => $row->cover_url, 'to' => '/movies/' . $row->code, 'label' => $row->code, 'movie_code' => $row->code], $covers['rows']),
            ],
            'meta' => ['page' => $page, 'per_page' => $perPage],
        ];
    }

    /**
     * Genres, makers, series and labels whose name contains the typed text or one of its longer words.
     *
     * @return list<array{kind: string, name: string, n: int}>
     */
    public function catalogue(string $text): array
    {
        $text = mb_strtolower(trim($text));

        if (mb_strlen($text) < 2) {
            return [];
        }

        $words = array_filter(preg_split('/\s+/', $text) ?: [], static fn(string $word): bool => mb_strlen($word) > 3);
        $patterns = array_map(SqlConditions::like(...), array_values(array_unique([$text, ...$words])));

        return array_slice($this->repository->catalogue($patterns), 0, 24);
    }

    /** @param array<string, mixed> $query */
    private function hasPerformerFilter(array $query): bool
    {
        foreach (CatalogueFilters::PERFORMER_KEYS as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function avatar(object $row): array
    {
        return ['kind' => 'avatar', 'thumb_url' => $row->photo_url, 'full_url' => $row->photo_url, 'label' => $row->name_romaji, 'performer_id' => (int) $row->id];
    }
}

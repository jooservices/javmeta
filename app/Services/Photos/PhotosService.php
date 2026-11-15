<?php

declare(strict_types=1);

namespace App\Services\Photos;

use App\Data\Photos\GalleryId;
use App\Data\Photos\PhotosGalleryQuery;
use App\Data\Photos\PhotosGroupQuery;
use App\Data\Photos\PhotosImagesQuery;
use App\Exceptions\InvalidFilterException;
use App\Repositories\PhotosRepository;
use Illuminate\Support\Facades\Cache;

/** Read-only photos browse API with bounded cache keys. */
final class PhotosService
{
    private const CACHE_TTL = 120;

    public function __construct(private readonly PhotosRepository $photos) {}

    /** @return array{items: list<array<string, mixed>>, total: int, per_page: int, page: int, last_page: int, by: string} */
    public function groups(PhotosGroupQuery $query): array
    {
        $key = 'javmeta:photos:groups:' . hash('sha256', json_encode([
            $query->by, $query->q, $query->source, $query->performerId, $query->movieCode, $query->page, $query->perPage,
        ], JSON_THROW_ON_ERROR));
        /** @var array{items: list<array<string, mixed>>, total: int} $result */
        $result = Cache::remember($key, self::CACHE_TTL, fn(): array => $this->photos->groups($query));

        return $this->paginate($result, $query->page, $query->perPage) + ['by' => $query->by];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, per_page: int, page: int, last_page: int} */
    public function galleries(PhotosGalleryQuery $query): array
    {
        $key = 'javmeta:photos:galleries:' . hash('sha256', json_encode([
            $query->performerId, $query->movieCode, $query->source, $query->q, $query->page, $query->perPage,
        ], JSON_THROW_ON_ERROR));
        /** @var array{items: list<array<string, mixed>>, total: int} $result */
        $result = Cache::remember($key, self::CACHE_TTL, fn(): array => $this->photos->galleries($query));

        return $this->paginate($result, $query->page, $query->perPage);
    }

    /** @return array{gallery: array<string, mixed>, items: list<array<string, mixed>>, total: int, per_page: int, page: int, last_page: int} */
    public function images(PhotosImagesQuery $query): array
    {
        try {
            $id = GalleryId::decode($query->galleryId);
        } catch (InvalidFilterException $e) {
            throw $e;
        }

        $key = 'javmeta:photos:images:' . hash('sha256', json_encode([
            $query->galleryId, $query->page, $query->perPage,
        ], JSON_THROW_ON_ERROR));
        /** @var array{gallery: array<string, mixed>|null, items: list<array<string, mixed>>, total: int} $result */
        $result = Cache::remember($key, self::CACHE_TTL, fn(): array => $this->photos->galleryImages($id, $query->page, $query->perPage));
        if ($result['gallery'] === null) {
            throw new InvalidFilterException(__('photos.gallery_not_found'));
        }

        return [
            'gallery' => $result['gallery'],
            'items' => $result['items'],
            'total' => $result['total'],
            'per_page' => $query->perPage,
            'page' => $query->page,
            'last_page' => max(1, (int) ceil($result['total'] / $query->perPage)),
        ];
    }

    /** @return array{facet: string, items: list<array<string, mixed>>, total: int, hidden_below_min: int, per_page: int, page: int, last_page: int, sort: string, min_count: int} */
    public function facetValues(string $facet, string $q, int $page, int $perPage, int $minCount, string $sort): array
    {
        if (! in_array($facet, ['source', 'performer'], true)) {
            throw new InvalidFilterException(__('photos.invalid_facet'));
        }
        $key = 'javmeta:photos:facets:' . hash('sha256', json_encode([
            $facet, $q, $page, $perPage, $minCount, $sort,
        ], JSON_THROW_ON_ERROR));
        /** @var array{items: list<array<string, mixed>>, total: int, hidden_below_min: int} $result */
        $result = Cache::remember(
            $key,
            self::CACHE_TTL,
            fn(): array => $this->photos->facetValues($facet, $q, $page, $perPage, $minCount, $sort),
        );

        return [
            'facet' => $facet,
            'items' => $result['items'],
            'total' => $result['total'],
            'hidden_below_min' => $result['hidden_below_min'],
            'per_page' => $perPage,
            'page' => $page,
            'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            'sort' => $sort,
            'min_count' => $minCount,
        ];
    }

    /**
     * @param array{items: list<array<string, mixed>>, total: int} $result
     * @return array{items: list<array<string, mixed>>, total: int, per_page: int, page: int, last_page: int}
     */
    private function paginate(array $result, int $page, int $perPage): array
    {
        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'per_page' => $perPage,
            'page' => $page,
            'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
        ];
    }
}

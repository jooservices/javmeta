<?php

declare(strict_types=1);

namespace App\Http\Resources\Concerns;

use App\Data\Api\PhotoDto;
use App\Data\Api\PhotoGalleryDto;
use App\Data\Api\PhotosDto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait FormatsPhotos
{
    /**
     * @param  Collection<int, Model>  $media
     * @param  list<string>  $imageKinds
     */
    private function photos(Collection $media, array $imageKinds, string $galleryKind): PhotosDto
    {
        $images = $media
            ->filter(fn(Model $item): bool => in_array((string) $item->getAttribute('kind'), $imageKinds, true))
            ->map(fn(Model $item): PhotoDto => $this->photo($item))
            ->values()
            ->all();

        /** @var array<string, list<Model>> $grouped */
        $grouped = [];
        foreach ($media->filter(fn(Model $item): bool => $item->getAttribute('kind') === $galleryKind) as $item) {
            $meta = $this->photoMeta($item);
            $galleryKey = (string) ($meta['gallery_id'] ?? $meta['gallery_url'] ?? $item->getAttribute('url'));
            $source = (string) ($item->getAttribute('source_slug') ?: 'unknown');
            $grouped[$source . '|' . $galleryKey][] = $item;
        }

        $galleries = [];
        foreach ($grouped as $items) {
            usort($items, function (Model $left, Model $right): int {
                $leftPosition = $this->photoMeta($left)['position'] ?? PHP_INT_MAX;
                $rightPosition = $this->photoMeta($right)['position'] ?? PHP_INT_MAX;

                return ((int) $leftPosition) <=> ((int) $rightPosition);
            });

            $first = $items[0];
            $meta = $this->photoMeta($first);
            $galleries[] = new PhotoGalleryDto(
                id: $this->stringMeta($meta, 'gallery_id'),
                title: $this->stringMeta($meta, 'gallery_title'),
                url: $this->stringMeta($meta, 'gallery_url'),
                source: (string) ($first->getAttribute('source_slug') ?: 'unknown'),
                crawledAt: $this->photoDate($first->getAttribute('crawled_at')),
                images: array_map(fn(Model $item): PhotoDto => $this->photo($item), $items),
            );
        }

        return new PhotosDto(images: $images, galleries: $galleries);
    }

    private function photo(Model $media): PhotoDto
    {
        $meta = $this->photoMeta($media);

        return new PhotoDto(
            url: (string) $media->getAttribute('url'),
            thumbnailUrl: $this->stringMeta($meta, 'thumbnail_url'),
            crawledAt: $this->photoDate($media->getAttribute('crawled_at')),
            source: (string) ($media->getAttribute('source_slug') ?: 'unknown'),
        );
    }

    /** @return array<string, mixed> */
    private function photoMeta(Model $media): array
    {
        $meta = $media->getAttribute('meta');

        return is_array($meta) ? $meta : [];
    }

    /** @param array<string, mixed> $meta */
    private function stringMeta(array $meta, string $key): ?string
    {
        return is_string($meta[$key] ?? null) && $meta[$key] !== '' ? $meta[$key] : null;
    }

    private function photoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}

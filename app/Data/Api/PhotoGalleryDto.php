<?php

declare(strict_types=1);

namespace App\Data\Api;

use JOOservices\Dto\Attributes\MapTo;
use JOOservices\Dto\Core\Dto;

final class PhotoGalleryDto extends Dto
{
    /**
     * @param  list<PhotoDto>  $images
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $title,
        public readonly ?string $url,
        public readonly string $source,
        #[MapTo('crawled_at')]
        public readonly ?string $crawledAt,
        public readonly array $images,
    ) {}

    /** @param array<string, mixed> $data */
    protected function beforeSerialization(array $data): array
    {
        $data['images'] = array_map(
            static fn(PhotoDto $photo): array => $photo->toArray(),
            $this->images,
        );

        return $data;
    }
}

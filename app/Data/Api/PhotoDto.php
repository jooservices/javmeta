<?php

declare(strict_types=1);

namespace App\Data\Api;

use JOOservices\Dto\Attributes\MapTo;
use JOOservices\Dto\Core\Dto;

final class PhotoDto extends Dto
{
    public function __construct(
        public readonly string $url,
        #[MapTo('thumbnail_url')]
        public readonly ?string $thumbnailUrl,
        #[MapTo('crawled_at')]
        public readonly ?string $crawledAt,
        public readonly string $source,
    ) {}
}

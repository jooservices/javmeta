<?php

declare(strict_types=1);

namespace App\Data\Api;

final readonly class MovieResourceData
{
    /**
     * @param  list<array{code: string, kind: string}>  $codes
     * @param  list<array<string, mixed>>  $performers
     * @param  list<string>  $genres
     * @param  list<string>  $directors
     * @param  array<string, mixed>  $attrs
     * @param  list<array{url: string, crawled_at: string|null}>  $magnets
     * @param  list<array{url: string, crawled_at: string|null}>  $hls_stream_urls
     * @param  list<array{url: string, crawled_at: string|null}>  $gallery
     */
    public function __construct(
        public string $code,
        public ?string $uuid = null,
        public array $codes = [],
        public ?string $title_jp = null,
        public ?string $title_en = null,
        public ?string $description = null,
        public array $performers = [],
        public array $directors = [],
        public ?string $cover_url = null,
        public ?string $cover_thumb_url = null,
        public ?string $release_date = null,
        public ?int $runtime_minutes = null,
        public ?string $maker = null,
        public ?string $label = null,
        public ?string $series = null,
        public array $genres = [],
        public ?bool $censored = null,
        public ?float $community_score = null,
        public int $completeness_tier = 0,
        public array $attrs = [],
        public array $magnets = [],
        public array $hls_stream_urls = [],
        public array $gallery = [],
        public PhotosDto $photos = new PhotosDto(),
        public ?string $crawled_at = null,
        public ?string $delisted_at = null,
        public bool $needs_review = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'codes' => $this->codes,
            'title_jp' => $this->title_jp,
            'title_en' => $this->title_en,
            'description' => $this->description,
            'performers' => $this->performers,
            'directors' => $this->directors,
            'cover_url' => $this->cover_url,
            'cover_thumb_url' => $this->cover_thumb_url,
            'release_date' => $this->release_date,
            'runtime_minutes' => $this->runtime_minutes,
            'maker' => $this->maker,
            'label' => $this->label,
            'series' => $this->series,
            'genres' => $this->genres,
            'censored' => $this->censored,
            'community_score' => $this->community_score,
            'completeness_tier' => $this->completeness_tier,
            'attrs' => $this->attrs,
            'magnets' => $this->magnets,
            'hls_stream_urls' => $this->hls_stream_urls,
            'gallery' => $this->gallery,
            'photos' => $this->photos->toArray(),
            'crawled_at' => $this->crawled_at,
            'delisted_at' => $this->delisted_at,
            'needs_review' => $this->needs_review,
        ];
    }
}

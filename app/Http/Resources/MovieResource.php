<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Api\MovieResourceData;
use App\Http\Resources\Concerns\FormatsResourceValues;
use App\Http\Resources\Concerns\FormatsPhotos;
use App\Models\Movie;
use App\Models\MovieCredit;
use App\Models\MovieMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full movie detail for GET /movies/{code}.
 * End-user contract: no source provenance (ops-only).
 */
final class MovieResource extends JsonResource
{
    use FormatsResourceValues;
    use FormatsPhotos;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if ($resource instanceof MovieResourceData) {
            return $resource->toArray();
        }

        if (! $resource instanceof Movie) {
            return is_array($resource) ? $resource : [];
        }

        return [
            'uuid' => $resource->uuid,
            'code' => $resource->display_code,
            'codes' => $this->whenLoaded('codes', fn(): array => $resource->codes->map(fn($code): array => [
                'code' => $code->code,
                'kind' => $code->kind,
            ])->all(), []),
            'title_jp' => $resource->title_jp,
            'title_en' => $resource->title_en,
            'description' => $resource->description,
            'performers' => $this->whenLoaded(
                'performers',
                fn(): array => PerformerSummaryResource::collection($resource->performers)->resolve($request),
                [],
            ),
            'directors' => $this->directors($resource),
            'cover_url' => $this->coverUrl($resource),
            'cover_thumb_url' => $resource->cover_thumb_url,
            'release_date' => $this->dateString($resource->getAttribute('release_date')),
            'runtime_minutes' => $resource->runtime_minutes,
            'maker' => $resource->maker,
            'label' => $resource->label,
            'series' => $resource->series,
            'genres' => $this->whenLoaded('genres', fn(): array => $resource->genres->pluck('label_raw')->unique()->values()->all(), []),
            'censored' => $resource->censored === null ? null : $resource->censored === Movie::CENSORED_CENSORED,
            'community_score' => $resource->community_score === null ? null : (float) $resource->community_score,
            'completeness_tier' => $resource->completeness_tier,
            'attrs' => $this->attrsArray($resource->getAttribute('attrs')),
            'magnets' => $this->media($resource, MovieMedia::KIND_MAGNET),
            'hls_stream_urls' => $this->media($resource, MovieMedia::KIND_HLS),
            'gallery' => $this->media($resource, MovieMedia::KIND_GALLERY),
            'photos' => $this->photos(
                $resource->relationLoaded('media') ? $resource->media : new Collection(),
                [MovieMedia::KIND_SAMPLE],
                MovieMedia::KIND_GALLERY,
            )->toArray(),
            'crawled_at' => $this->dateTimeString($resource->getAttribute('crawled_at')),
            'delisted_at' => $this->dateTimeString($resource->getAttribute('delisted_at')),
            'needs_review' => (bool) $resource->needs_review,
        ];
    }

    private function coverUrl(Movie $movie): ?string
    {
        if (is_string($movie->cover_url) && $movie->cover_url !== '') {
            return $movie->cover_url;
        }

        if (! $movie->relationLoaded('media')) {
            return null;
        }

        $media = $movie->media->firstWhere('kind', MovieMedia::KIND_SAMPLE);

        return is_string($media?->url) ? $media->url : null;
    }

    /** @return list<string> */
    private function directors(Movie $movie): array
    {
        if (! $movie->relationLoaded('credits')) {
            return [];
        }

        return $movie->credits
            ->where('role', MovieCredit::ROLE_DIRECTOR)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<array{url: string, crawled_at: string|null, thumbnail_url?: string|null}> */
    private function media(Movie $movie, string $kind): array
    {
        if (! $movie->relationLoaded('media')) {
            return [];
        }

        return $movie->media
            ->where('kind', $kind)
            ->map(function ($media) use ($kind): array {
                $item = [
                    'url' => $media->url,
                    'crawled_at' => $this->dateTimeString($media->getAttribute('crawled_at')),
                ];

                if ($kind === MovieMedia::KIND_GALLERY) {
                    $meta = $media->getAttribute('meta');
                    $item['thumbnail_url'] = is_array($meta) && is_string($meta['thumbnail_url'] ?? null)
                        ? $meta['thumbnail_url']
                        : null;
                }

                return $item;
            })
            ->values()
            ->all();
    }
}

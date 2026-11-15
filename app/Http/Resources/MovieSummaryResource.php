<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Api\MovieResourceData;
use App\Http\Resources\Concerns\FormatsResourceValues;
use App\Models\Movie;
use App\Models\MovieMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim movie payload for search and bulk lookup.
 */
final class MovieSummaryResource extends JsonResource
{
    use FormatsResourceValues;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if ($resource instanceof MovieResourceData) {
            return [
                'uuid' => $resource->uuid,
                'code' => $resource->code,
                'title_jp' => $resource->title_jp,
                'title_en' => $resource->title_en,
                'performers' => $resource->performers,
                'cover_url' => $resource->cover_url,
                'cover_thumb_url' => $resource->cover_thumb_url,
                'release_date' => $resource->release_date,
                'runtime_minutes' => $resource->runtime_minutes,
                'maker' => $resource->maker,
                'label' => $resource->label,
                'series' => $resource->series,
                'genres' => $resource->genres,
                'censored' => $resource->censored,
                'community_score' => $resource->community_score,
                'completeness_tier' => $resource->completeness_tier,
                'needs_review' => $resource->needs_review,
            ];
        }

        if (! $resource instanceof Movie) {
            return is_array($resource) ? $resource : [];
        }

        return [
            'uuid' => $resource->uuid,
            'code' => $resource->display_code,
            'title_jp' => $resource->title_jp,
            'title_en' => $resource->title_en,
            'performers' => $this->whenLoaded(
                'performers',
                fn(): array => PerformerSummaryResource::collection($resource->performers)->resolve($request),
                [],
            ),
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
            'needs_review' => (bool) $resource->needs_review,
        ];
    }

    private function coverUrl(Movie $movie): ?string
    {
        if (is_string($movie->cover_url) && $movie->cover_url !== '') {
            return $movie->cover_url;
        }

        if ($movie->relationLoaded('coverMedia')) {
            return is_string($movie->coverMedia?->url) ? $movie->coverMedia->url : null;
        }

        if (! $movie->relationLoaded('media')) {
            return null;
        }
        $media = $movie->media->firstWhere('kind', MovieMedia::KIND_SAMPLE);

        return is_string($media?->url) ? $media->url : null;
    }
}

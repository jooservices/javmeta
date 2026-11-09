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
            $payload = [
                'uuid' => $resource->uuid,
                'code' => $this->displayCode($resource->code),
                'title_jp' => $resource->title_jp,
                'title_en' => $this->title($resource->title_en, $resource->title_jp),
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

            return $this->includeInternalCode($payload, $resource->code, $request);
        }

        if (! $resource instanceof Movie) {
            return is_array($resource) ? $resource : [];
        }

        $payload = [
            'uuid' => $resource->uuid,
            'code' => $this->displayCode((string) $resource->display_code),
            'title_jp' => $resource->title_jp,
            'title_en' => $this->title($resource->title_en, $resource->title_jp),
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

        return $this->includeInternalCode($payload, (string) $resource->display_code, $request);
    }

    private function title(?string $english, ?string $japanese): ?string
    {
        foreach ([$english, $japanese] as $title) {
            if (is_string($title) && trim($title) !== '') {
                return $title;
            }
        }

        return null;
    }

    private function displayCode(string $code): string
    {
        if (preg_match('/^(.+)-DERIVED-([A-Fa-f0-9]{6})[A-Fa-f0-9]*$/', $code, $match) !== 1) {
            return $code;
        }

        return strtoupper($match[1]) . ' · #' . strtoupper($match[2]);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function includeInternalCode(array $payload, string $code, Request $request): array
    {
        if ((string) $request->query('include_internal_code', '0') === '1' && $this->displayCode($code) !== $code) {
            $payload['internal_code'] = $code;
        }

        return $payload;
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
}

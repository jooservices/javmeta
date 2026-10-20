<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Crawl\PerformerDraft;
use App\Data\Api\PhotosDto;
use App\Http\Resources\Concerns\FormatsPhotos;
use App\Http\Resources\Concerns\FormatsResourceValues;
use App\Models\Performer;
use App\Models\PerformerMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full performer detail for GET /performers/{id}.
 */
final class PerformerResource extends JsonResource
{
    use FormatsResourceValues;
    use FormatsPhotos;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if ($resource instanceof PerformerDraft) {
            return array_merge(
                (new PerformerSummaryResource($resource))->toArray($request),
                [
                    'birth_date' => null,
                    'height_cm' => null,
                    'bust' => null,
                    'waist' => null,
                    'hip' => null,
                    'cup' => null,
                    'blood_type' => null,
                    'bio_text' => null,
                    'debut_date' => null,
                    'retired_date' => null,
                    'location' => null,
                    'attrs' => [],
                    'linked_title_count' => null,
                    'photos' => (new PhotosDto())->toArray(),
                ],
            );
        }

        if ($resource instanceof Performer) {
            return [
                'uuid' => $resource->uuid,
                'id' => $resource->id,
                'name_romaji' => $resource->name_romaji,
                'name_kanji' => $resource->name_kanji,
                'name_kana' => $resource->name_kana,
                'aliases' => $this->whenLoaded('aliases', fn(): array => $resource->aliases->pluck('alias')->all(), []),
                'sources' => $this->whenLoaded(
                    'sources',
                    fn(): array => $resource->sources->map(fn($source): array => (new PerformerSourceResource($source))->toArray($request))->all(),
                    [],
                ),
                'birth_date' => $this->dateString($resource->getAttribute('birth_date')),
                'height_cm' => $resource->height_cm,
                'bust' => $resource->bust,
                'waist' => $resource->waist,
                'hip' => $resource->hip,
                'cup' => $resource->cup,
                'blood_type' => $resource->blood_type,
                'bio_text' => $resource->bio_text,
                'debut_date' => $this->dateString($resource->getAttribute('debut_date')),
                'retired_date' => $this->dateString($resource->getAttribute('retired_date')),
                'location' => $resource->location,
                'attrs' => $this->attrsArray($resource->getAttribute('attrs')),
                'linked_title_count' => $this->whenCounted('movies'),
                'profile_url' => $resource->profile_url,
                'image_url' => $resource->image_url,
                'photos' => $this->photos(
                    $resource->relationLoaded('media') ? $resource->media : new Collection(),
                    [PerformerMedia::KIND_IMAGE],
                    PerformerMedia::KIND_GALLERY,
                )->toArray(),
            ];
        }

        return is_array($resource) ? $resource : [];
    }
}

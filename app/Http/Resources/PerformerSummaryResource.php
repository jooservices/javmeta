<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Data\Crawl\PerformerDraft;
use App\Http\Resources\Concerns\FormatsResourceValues;
use App\Models\Performer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Slim performer payload for list endpoints and nested movie.performers.
 */
final class PerformerSummaryResource extends JsonResource
{
    use FormatsResourceValues;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $resource = $this->resource;

        if ($resource instanceof PerformerDraft) {
            return [
                'uuid' => null,
                'id' => $resource->externalId,
                'name_romaji' => $resource->nameRomaji,
                'name_kanji' => $resource->nameKanji,
                'name_kana' => $resource->nameKana,
                'aliases' => $resource->aliases,
                'image_url' => $resource->imageUrl,
                'profile_url' => $resource->profileUrl,
            ];
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
                'image_url' => $resource->image_url,
                'profile_url' => $resource->profile_url,
            ];
        }

        return is_array($resource) ? $resource : [];
    }
}

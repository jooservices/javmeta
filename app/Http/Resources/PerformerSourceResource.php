<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PerformerSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public view of one source record linked to a canonical performer. */
final class PerformerSourceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $source = $this->resource;
        if (! $source instanceof PerformerSource) {
            return is_array($source) ? $source : [];
        }

        return [
            'source_slug' => $source->site_slug,
            'external_id' => $source->external_id,
            'source_url' => $source->source_url,
            'name_romaji' => $source->name_romaji,
            'name_kanji' => $source->name_kanji,
            'name_kana' => $source->name_kana,
            'birth_date' => $this->dateString($source->getAttribute('birth_date')),
            'active' => $source->removed_at === null,
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}

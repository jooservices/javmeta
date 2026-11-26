<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use Carbon\Carbon;
use Illuminate\Support\Str;

/** Shapes rows of the search tables into the movie and performer summaries of the contract. */
final class Formatter
{
    /** @return array<string, mixed> */
    public function movie(object $row): array
    {
        return [
            'code' => $row->code,
            'title' => $row->title_en ?: $row->title_jp,
            'title_jp' => $row->title_jp,
            'title_en' => $row->title_en,
            'release_date' => $row->release_date,
            'runtime_minutes' => $row->runtime,
            'censored' => $row->censored,
            'maker' => $row->maker,
            'label' => $row->label,
            'series' => $row->series,
            'score' => $row->score === null ? null : (float) $row->score,
            'quality_tier' => $row->tier,
            'cover_url' => $row->cover_url,
            'cover_thumb_url' => $row->cover_thumb_url ?: $row->cover_url,
            'crawled_at' => $this->time($row->crawled_at),
            'genres' => $this->textArray($row->genres),
            'performer_ids' => array_map('intval', $this->textArray($row->performer_ids)),
            'download_count' => (int) $row->dl_count,
            'hls_count' => (int) $row->hls_count,
            'gallery_count' => (int) $row->gallery_count,
            'sources' => $this->textArray($row->sources),
            'needs_review' => (bool) $row->needs_review,
            'delisted' => (bool) $row->delisted,
        ];
    }

    /** @return array<string, mixed> */
    public function performer(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'slug' => $row->id . '-' . Str::slug((string) $row->name_romaji),
            'name' => $row->name_romaji,
            'name_kanji' => $row->name_kanji,
            'name_kana' => $row->name_kana,
            'birth_date' => $row->birth_date,
            'age' => $this->age($row->birth_date),
            'height_cm' => $row->height_cm,
            'bust' => $row->bust,
            'waist' => $row->waist,
            'hip' => $row->hip,
            'cup' => $row->cup,
            'blood_type' => $row->blood_type,
            'blood_rh' => $row->blood_rh,
            'location' => $row->location,
            'movie_count' => (int) $row->movie_count,
            'photo_url' => $row->photo_url,
            'photo_count' => (int) $row->photo_count,
            'debut_date' => $row->debut_date,
            'retired_date' => $row->retired_date,
            'aliases' => $this->textArray($row->aliases),
            'crawled_at' => $this->time($row->crawled_at),
            'first_seen_at' => $this->time($row->first_seen_at),
            'source_count' => (int) $row->source_count,
        ] + (isset($row->together) ? ['together' => (int) $row->together] : []);
    }

    /** @return list<string> */
    private function textArray(?string $literal): array
    {
        if ($literal === null || $literal === '{}' || $literal === '') {
            return [];
        }

        $parsed = str_getcsv(trim($literal, '{}'), ',', '"', '\\');

        return array_map(static fn(?string $v): string => (string) $v, $parsed);
    }

    private function age(?string $birth): ?int
    {
        return $birth === null ? null : Carbon::parse($birth)->age;
    }

    private function time(?string $value): ?string
    {
        return $value === null ? null : date('c', (int) strtotime($value));
    }
}

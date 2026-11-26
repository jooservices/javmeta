<?php

declare(strict_types=1);

namespace Tests\Support\Api\V2;

/** Canned rows shaped like the PostgreSQL search tables (rm_movies, rm_performers) the v2 formatters read. */
final class ReadModelRows
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function movie(array $over = []): array
    {
        return $over + [
            'id' => 1, 'code' => 'AAA-001', 'title_jp' => 'タイトル', 'title_en' => 'Title', 'release_date' => '2024-05-01', 'runtime' => 120,
            'censored' => true, 'maker' => 'Maker', 'label' => 'Label', 'series' => 'Series', 'score' => '4.5', 'tier' => 2,
            'cover_url' => 'https://img/c.jpg', 'cover_thumb_url' => 'https://img/t.jpg', 'crawled_at' => '2024-05-02 10:00:00',
            'genres' => '{Drama,"Big, Bold"}', 'performer_ids' => '{7,8}', 'dl_count' => '3', 'hls_count' => '1', 'gallery_count' => '2',
            'sources' => '{alpha,beta}', 'needs_review' => false, 'delisted' => false,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function performer(array $over = []): array
    {
        return $over + [
            'id' => 7, 'name_romaji' => 'Yui Hatano', 'name_kanji' => '波多野結衣', 'name_kana' => 'はたのゆい', 'birth_date' => '1988-05-24',
            'height_cm' => 163, 'bust' => 88, 'waist' => 58, 'hip' => 87, 'cup' => 'F', 'blood_type' => 'A', 'blood_rh' => '+', 'location' => 'Tokyo',
            'movie_count' => '12', 'photo_url' => 'https://img/p.jpg', 'photo_count' => '4', 'debut_date' => '2008-01-01', 'retired_date' => null,
            'aliases' => '{Yui}', 'crawled_at' => '2024-05-02 10:00:00', 'first_seen_at' => '2024-01-01 00:00:00', 'source_count' => '2',
        ];
    }
}

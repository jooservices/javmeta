<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use Illuminate\Support\Facades\DB;

/** Seeds the search tables directly so every number the discovery tests assert is known. */
abstract class DiscoveryTestCase extends PostgresApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSearchTables();
    }

    protected function seedSearchTables(): void
    {
        $now = now('UTC');
        $this->performer(1, 'Aoi Test', ['birth_date' => '1999-05-01', 'height_cm' => 160, 'cup' => 'D', 'blood_type' => 'A', 'location' => 'Tokyo', 'movie_count' => 3, 'photo_url' => 'https://img.test/aoi.jpg', 'crawled_at' => $now->copy()->subHours(2), 'first_seen_at' => $now->copy()->subHours(2)]);
        $this->performer(2, 'Mio Sample', ['height_cm' => 150, 'cup' => 'B', 'blood_type' => 'O', 'location' => 'Osaka', 'movie_count' => 1, 'crawled_at' => $now->copy()->subDays(3), 'first_seen_at' => $now->copy()->subDays(3)]);
        $this->performer(3, 'Unknown Person', ['movie_count' => 0]);

        $this->movie(1, 'AAA-001', 'First alpha', ['release_date' => '2025-03-01', 'year' => 2025, 'runtime' => 90, 'maker' => 'Maker One', 'label' => 'Label One', 'series' => 'Series One', 'score' => 8.5, 'tier' => 3, 'cover_url' => 'https://img.test/a.jpg', 'crawled_at' => $now->copy()->subHours(1), 'first_seen_at' => $now->copy()->subHours(1), 'genres' => '{Drama,Comedy}', 'performer_ids' => '{1}', 'dl_count' => 2, 'hls_count' => 1, 'gallery_count' => 4, 'sources' => '{alpha-src}']);
        $this->movie(2, 'BBB-002', 'Second beta', ['release_date' => '2025-03-01', 'year' => 2025, 'runtime' => 150, 'maker' => 'Maker Two', 'score' => 6.0, 'tier' => 2, 'cover_url' => 'https://img.test/b.jpg', 'crawled_at' => $now->copy()->subDays(2), 'first_seen_at' => $now->copy()->subDays(2), 'genres' => '{Drama}', 'performer_ids' => '{1,2}', 'sources' => '{alpha-src,beta-src}']);
        $this->movie(3, 'CCC-003', 'Third gamma', ['release_date' => '2024-12-24', 'year' => 2024, 'runtime' => 45, 'tier' => 1, 'genres' => '{}', 'performer_ids' => '{}']);
        $this->movie(4, 'DDD-004', 'Hidden delta', ['release_date' => '2025-03-02', 'year' => 2025, 'delisted' => 'true', 'crawled_at' => $now->copy()->subHours(1)]);

        DB::table('rm_refresh')->insert(['name' => 'movies', 'refreshed_at' => $now, 'row_count' => 4, 'duration_ms' => 5]);
        DB::table('sources')->insert([
            ['slug' => 'alpha-src', 'name' => 'Alpha Source', 'base_url' => 'https://alpha.test', 'enabled' => true, 'priority' => 1, 'gap_seconds_default' => 1, 'gap_seconds_current' => 1],
            ['slug' => 'beta-src', 'name' => 'Beta Source', 'base_url' => 'https://beta.test', 'enabled' => false, 'priority' => 2, 'gap_seconds_default' => 1, 'gap_seconds_current' => 1],
        ]);
        DB::table('crawl_stats_hourly')->insert([
            ['hour_at' => $now->copy()->startOfHour()->subHour(), 'source_slug' => 'alpha-src', 'lane' => 'http', 'method' => 'get', 'attempts' => 10, 'ok' => 8, 'blocked' => 1, 'timeout' => 1, 'other_error' => 0],
            ['hour_at' => $now->copy()->startOfHour()->subHour(), 'source_slug' => '__global__', 'lane' => 'http', 'method' => 'get', 'attempts' => 99, 'ok' => 99, 'blocked' => 0, 'timeout' => 0, 'other_error' => 0],
        ]);
    }

    /** @param array<string, mixed> $over */
    protected function movie(int $id, string $code, string $title, array $over = []): void
    {
        DB::table('rm_movies')->insert($over + [
            'id' => $id,
            'code' => $code,
            'code_norm' => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)),
            'title_en' => $title,
            'search_text' => strtolower($code . ' ' . $title . ' ' . ($over['maker'] ?? '')),
        ]);
    }

    /** @param array<string, mixed> $over */
    protected function performer(int $id, string $name, array $over = []): void
    {
        DB::table('rm_performers')->insert($over + ['id' => $id, 'name_romaji' => $name, 'search_text' => strtolower($name)]);
    }

    /** Enough movies of one genre, maker and series for the question reader to know them. */
    protected function seedCatalogueWords(): void
    {
        DB::statement("INSERT INTO rm_movies (id, code, code_norm, genres, maker, series, search_text) SELECT 1000 + g, 'FILL-' || g, 'FILL' || g, '{Slender Genre}', 'Acme Studio', 'Long Saga', 'fill' FROM generate_series(1, 120) g");
    }
}

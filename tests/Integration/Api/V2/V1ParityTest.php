<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieGenre;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use App\Services\Normalize\PerformerAttributeNormalizer;

/**
 * Data mapping between the existing v1 API (what the legacy UI and API clients read) and the new v2 API on the same
 * record: every v1 field that the UI used must reach v2 under its v2 name with the same value.
 */
final class V1ParityTest extends PostgresApiTestCase
{
    /** v1 field => v2 field of a movie summary */
    private const MOVIE_FIELDS = [
        'code' => 'code', 'title_jp' => 'title_jp', 'title_en' => 'title_en', 'release_date' => 'release_date', 'runtime_minutes' => 'runtime_minutes',
        'maker' => 'maker', 'label' => 'label', 'series' => 'series', 'censored' => 'censored', 'community_score' => 'score',
        'completeness_tier' => 'quality_tier', 'cover_url' => 'cover_url', 'cover_thumb_url' => 'cover_thumb_url', 'needs_review' => 'needs_review',
    ];

    /** v1 field => v2 field of a performer */
    private const PERFORMER_FIELDS = [
        'id' => 'id', 'name_romaji' => 'name', 'name_kanji' => 'name_kanji', 'name_kana' => 'name_kana', 'birth_date' => 'birth_date', 'height_cm' => 'height_cm',
        'bust' => 'bust', 'waist' => 'waist', 'hip' => 'hip', 'cup' => 'cup', 'blood_type' => 'blood_type', 'location' => 'location', 'blood_rh' => 'blood_rh', 'bio_text' => 'bio_text',
        'debut_date' => 'debut_date', 'retired_date' => 'retired_date', 'aliases' => 'aliases', 'image_url' => 'photo_url',
    ];

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = app(ApiKeyService::class)->create('parity', 600, ['movies', 'performers'])->plaintext;
        $star = Performer::factory()->create([
            'name_romaji' => 'Aoi Parity', 'name_kanji' => '葵', 'name_kana' => 'あおい', 'birth_date' => '1997-02-03', 'height_cm' => 158, 'bust' => 84, 'waist' => 58, 'hip' => 85,
            'cup' => 'D', 'blood_type' => 'A', 'bio_text' => 'Debuted in 2016.', 'debut_date' => '2016-05-01', 'location' => 'Osaka', 'image_url' => 'https://img.test/aoi.jpg', 'retired_date' => null,
        ]);
        $movie = Movie::factory()->create([
            'display_code' => 'PAR-001', 'title_jp' => '日本語', 'title_en' => 'Parity movie', 'release_date' => '2025-03-04', 'runtime_minutes' => 120, 'maker' => 'Maker P', 'label' => 'Label P',
            'series' => 'Series P', 'community_score' => 8.5, 'cover_url' => 'https://img.test/cover.jpg', 'completeness_tier' => 3, 'needs_review' => false,
        ]);
        MoviePerformer::factory()->create(['movie_id' => $movie->id, 'performer_id' => $star->id]);
        $genre = Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id]);
        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function v1(string $path): array
    {
        return $this->getJson('/api/v1/' . $path, ['X-API-Key' => $this->key])->assertOk()->json();
    }

    public function test_a_movie_keeps_every_v1_field_in_v2_with_the_same_value(): void
    {
        $v1 = $this->v1('movies?q=PAR-001')['data'][0];
        $v2 = $this->getJson('/api/v2/movies?q=PAR-001')->assertOk()->json('data.0');

        foreach (self::MOVIE_FIELDS as $old => $new) {
            self::assertArrayHasKey($new, $v2, "v2 lost {$old}");
            // v2 deliberately falls back to the full cover when a movie has no thumbnail, so the UI always has a picture to show.
            $expected = $old === 'cover_thumb_url' ? ($v1[$old] ?? $v1['cover_url']) : ($v1[$old] ?? null);
            self::assertEquals($expected, $v2[$new], "{$old} -> {$new}");
        }

        self::assertSame($v1['uuid'], $this->getJson('/api/v2/movies/PAR-001')->assertOk()->json('data.uuid'), 'the uuid is on the v2 detail');
        self::assertEqualsCanonicalizing($v1['genres'], $v2['genres'], 'genres');
        self::assertSame(array_column($v1['performers'], 'id'), $v2['performer_ids'], 'cast');
        self::assertSame(array_column($v1['performers'], 'name_romaji'), array_column($v2['performers'], 'name'), 'cast names');
    }

    public function test_a_performer_keeps_every_v1_field_in_v2_with_the_same_value(): void
    {
        $id = Performer::query()->where('name_romaji', 'Aoi Parity')->value('id');
        $v1 = $this->v1("performers/{$id}")['data'];
        $v2 = $this->getJson("/api/v2/performers/{$id}")->assertOk()->json('data');

        $normalizer = new PerformerAttributeNormalizer();
        foreach (self::PERFORMER_FIELDS as $old => $new) {
            self::assertArrayHasKey($new, $v2, "v2 lost {$old}");
            // Cup, blood type and location are shown in their canonical form in v2 (the form v1 groups them by in its facets).
            $expected = in_array($old, ['cup', 'blood_type', 'location'], true) && is_string($v1[$old] ?? null)
                ? ($normalizer->facetValue($old, mb_strtolower($v1[$old])) ?? $v1[$old])
                : ($v1[$old] ?? null);
            self::assertEquals($expected, $v2[$new], "{$old} -> {$new}");
        }

        self::assertSame($v1['linked_title_count'], $v2['movie_count'], 'linked_title_count -> movie_count');
    }

    /** A larger catalogue so lists, facets and the calendar have more than one row to compare. */
    private function moreMovies(): void
    {
        $performers = [Performer::query()->where('name_romaji', 'Aoi Parity')->first(), Performer::factory()->create(['name_romaji' => 'Mio Parity', 'cup' => 'B', 'blood_type' => 'O', 'location' => 'Tokyo'])];
        $drama = Genre::query()->where('label_normalized', 'drama')->first();
        $comedy = Genre::factory()->create(['label_normalized' => 'comedy', 'label_raw' => 'Comedy']);

        foreach (range(2, 13) as $i) {
            $movie = Movie::factory()->create([
                'display_code' => sprintf('PAR-%03d', $i), 'title_en' => "Parity movie {$i}", 'release_date' => sprintf('2025-%02d-%02d', ($i % 6) + 1, $i), 'maker' => 'Maker ' . ($i % 3),
                'label' => 'Label ' . ($i % 2), 'series' => $i % 4 === 0 ? null : 'Series ' . ($i % 4), 'community_score' => 3 + ($i % 6), 'completeness_tier' => $i % 4, 'updated_at' => now()->subMinutes($i),
            ]);
            MoviePerformer::factory()->create(['movie_id' => $movie->id, 'performer_id' => $performers[$i % 2]->id]);
            MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => ($i % 2 === 0 ? $drama : $comedy)->id]);
        }

        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();
    }

    /** @param list<array{name: string, count?: int, n?: int}> $rows */
    private static function counts(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['name']] = $row['count'] ?? $row['n'];
        }
        ksort($map);

        return $map;
    }

    public function test_lists_in_every_shared_sort_come_back_in_the_same_order(): void
    {
        $this->moreMovies();

        foreach (['release_date', 'update_date'] as $sort) {
            $v1 = [];
            $v2 = [];
            $cursor = '';
            do {
                $body = $this->v1("movies?sort={$sort}&per_page=5" . ($cursor === '' ? '' : "&cursor={$cursor}"));
                $v1 = [...$v1, ...array_column($body['data'], 'code')];
                $cursor = (string) ($body['meta']['pagination']['next_cursor'] ?? '');
            } while ($cursor !== '');
            foreach ([1, 2, 3] as $page) {
                $v2 = [...$v2, ...array_column($this->getJson("/api/v2/movies?sort={$sort}&per_page=5&page={$page}")->assertOk()->json('data'), 'code')];
            }

            self::assertCount(13, $v2, "{$sort}: all movies listed");
            self::assertSame($v1, $v2, "the {$sort} order");
        }
    }

    public function test_movie_facet_counts_are_the_same(): void
    {
        $this->moreMovies();
        $v1 = $this->v1('movies/facets')['data'];
        $v2 = $this->getJson('/api/v2/movies/facets')->assertOk()->json('data');

        foreach (['genres', 'makers', 'series', 'labels'] as $facet) {
            self::assertSame(self::counts($v1[$facet]), self::counts($v2[$facet]), "movie facet {$facet}");
        }
    }

    public function test_performer_facet_counts_are_the_same(): void
    {
        $this->moreMovies();
        $v1 = $this->v1('performers/facets')['data'];
        $v2 = $this->getJson('/api/v2/performers/facets')->assertOk()->json('data');

        foreach (['cups' => 'cups', 'blood_types' => 'blood', 'locations' => 'locations'] as $old => $new) {
            self::assertSame(self::counts($v1[$old]), self::counts($v2[$new]), "performer facet {$old}");
        }
        self::assertSame(self::counts($v1['status']), array_map('intval', $v2['enums']['status']) + [], 'performer status');
    }

    public function test_the_release_calendar_and_the_totals_are_the_same(): void
    {
        $this->moreMovies();
        $v1 = array_map(static fn(array $day): array => [$day['date'], $day['count']], $this->v1('movies/calendar?month=2025-03')['data']);
        $v2 = array_map(static fn(array $day): array => [$day['day'], $day['count']], $this->getJson('/api/v2/calendar?month=2025-03')->assertOk()->json('data'));

        self::assertSame($v1, $v2, 'calendar of one month');
    }

    public function test_the_movie_list_filters_of_v1_give_the_same_movies_in_v2(): void
    {
        foreach (['maker=Maker%20P' => 'maker', 'series=Series%20P' => 'series', 'label=Label%20P' => 'label', 'genre=drama' => 'genre', 'released_from=2025-01-01&released_to=2025-12-31' => 'dates'] as $query => $name) {
            $v1 = array_column($this->v1("movies?{$query}")['data'], 'code');
            $v2 = array_column($this->getJson("/api/v2/movies?{$query}")->assertOk()->json('data'), 'code');

            self::assertContains('PAR-001', $v1, "v1 {$name}");
            self::assertSame($v1, $v2, "the {$name} filter returns the same movies");
        }
    }
}

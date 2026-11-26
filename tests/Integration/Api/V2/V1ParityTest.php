<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieGenre;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;

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
        'bust' => 'bust', 'waist' => 'waist', 'hip' => 'hip', 'cup' => 'cup', 'blood_type' => 'blood_type', 'blood_rh' => 'blood_rh', 'bio_text' => 'bio_text',
        'debut_date' => 'debut_date', 'retired_date' => 'retired_date', 'location' => 'location', 'aliases' => 'aliases', 'image_url' => 'photo_url',
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

        foreach (self::PERFORMER_FIELDS as $old => $new) {
            self::assertArrayHasKey($new, $v2, "v2 lost {$old}");
            self::assertEquals($v1[$old] ?? null, $v2[$new], "{$old} -> {$new}");
        }

        self::assertSame($v1['linked_title_count'], $v2['movie_count'], 'linked_title_count -> movie_count');
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

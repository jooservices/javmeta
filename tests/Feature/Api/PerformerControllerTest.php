<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PerformerControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
    }

    public function test_performers_require_api_key(): void
    {
        $this->getJson('/api/v1/performers')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_show_requires_api_key(): void
    {
        $performer = Performer::factory()->create();

        $this->getJson('/api/v1/performers/' . $performer->id)
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_search_matches_name_romaji(): void
    {
        $name = fake()->name();
        $performer = Performer::factory()->create(['name_romaji' => $name]);

        $response = $this->getJson('/api/v1/performers?q=' . urlencode($name), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $performer->id);
    }

    public function test_search_matches_name_kanji(): void
    {
        $name = fake()->name();
        $performer = Performer::factory()->create(['name_kanji' => $name]);

        $response = $this->getJson('/api/v1/performers?q=' . urlencode($name), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $performer->id);
    }

    public function test_search_matches_name_kana(): void
    {
        $name = fake()->name();
        $performer = Performer::factory()->create(['name_kana' => $name]);

        $response = $this->getJson('/api/v1/performers?q=' . urlencode($name), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $performer->id);
    }

    public function test_search_matches_alias_ac53(): void
    {
        $performer = Performer::factory()->create();
        $alias = fake()->unique()->words(2, true);
        PerformerAlias::create([
            'performer_id' => $performer->id,
            'alias' => $alias,
            'kind' => 'stage',
        ]);

        $response = $this->getJson('/api/v1/performers?q=' . urlencode($alias), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $performer->id)
            ->assertJsonPath('data.0.aliases.0', $alias);
    }

    public function test_search_matches_bio_text(): void
    {
        $bioTerm = fake()->unique()->words(2, true);
        $performer = Performer::factory()->create(['bio_text' => "Profile {$bioTerm}"]);

        $response = $this->getJson('/api/v1/performers?q=' . urlencode($bioTerm), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $performer->id);
    }

    public function test_search_filters_age_blood_type_and_hip(): void
    {
        Carbon::setTestNow('2026-09-30');
        $matching = Performer::factory()->create([
            'birth_date' => '1996-01-01',
            'blood_type' => 'A',
            'hip' => 92,
        ]);
        Performer::factory()->create([
            'birth_date' => '1980-01-01',
            'blood_type' => 'A',
            'hip' => 92,
        ]);
        Performer::factory()->create([
            'birth_date' => '1996-01-01',
            'blood_type' => 'B',
            'hip' => 92,
        ]);

        $response = $this->getJson('/api/v1/performers?age_min=25&age_max=35&blood_type=A&hip_min=90&hip_max=95', [
            'X-API-Key' => $this->apiKey,
        ]);

        Carbon::setTestNow();

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_cursor_pagination_returns_non_overlapping_performer_pages(): void
    {
        $performers = Performer::factory()->count(5)->create();

        $pageOne = $this->getJson('/api/v1/performers?sort=name&per_page=2', [
            'X-API-Key' => $this->apiKey,
        ]);
        $pageOne->assertOk()
            ->assertJsonPath('meta.pagination.has_more', true)
            ->assertJsonStructure(['meta' => ['pagination' => ['next_cursor']]]);

        $cursor = $pageOne->json('meta.pagination.next_cursor');
        $pageTwo = $this->getJson('/api/v1/performers?sort=name&per_page=2&cursor=' . urlencode((string) $cursor), [
            'X-API-Key' => $this->apiKey,
        ]);
        $pageTwo->assertOk();

        $pageOneIds = collect($pageOne->json('data'))->pluck('id')->all();
        $pageTwoIds = collect($pageTwo->json('data'))->pluck('id')->all();

        $this->assertCount(2, $pageOneIds);
        $this->assertCount(2, $pageTwoIds);
        $this->assertSame([], array_intersect($pageOneIds, $pageTwoIds));
        $this->assertSame(5, $pageTwo->json('meta.pagination.total'));
        $this->assertCount(5, $performers);
    }

    public function test_cursor_with_changed_performer_sort_returns_invalid_filter(): void
    {
        Performer::factory()->count(3)->create();

        $pageOne = $this->getJson('/api/v1/performers?sort=name&per_page=2', [
            'X-API-Key' => $this->apiKey,
        ]);
        $cursor = $pageOne->json('meta.pagination.next_cursor');

        $response = $this->getJson('/api/v1/performers?sort=updated_at&per_page=2&cursor=' . urlencode((string) $cursor), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_show_accepts_performer_uuid(): void
    {
        $performer = Performer::factory()->create();

        $response = $this->getJson('/api/v1/performers/' . $performer->uuid, [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.uuid', $performer->uuid);
    }

    public function test_reversed_profile_range_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/performers?hip_min=100&hip_max=90', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_same_name_different_sources_remain_separate_ac52(): void
    {
        $name = fake()->name();
        $first = Performer::factory()->create(['name_romaji' => $name]);
        $second = Performer::factory()->create(['name_romaji' => $name]);

        $this->assertNotSame(
            [$first->source_slug, $first->external_id],
            [$second->source_slug, $second->external_id],
        );

        $response = $this->getJson('/api/v1/performers?q=' . urlencode($name), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_single_title_performer_is_returned_ac54(): void
    {
        $performer = Performer::factory()->create();
        $movie = Movie::factory()->create();
        MoviePerformer::factory()->create([
            'movie_id' => $movie->id,
            'performer_id' => $performer->id,
            'source_slug' => $performer->source_slug,
        ]);

        $response = $this->getJson('/api/v1/performers/' . $performer->id, [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $performer->id)
            ->assertJsonPath('data.linked_title_count', 1);
    }

    public function test_linked_title_count_counts_all_movie_performers_ac51(): void
    {
        $performer = Performer::factory()->create();
        $movies = Movie::factory()->count(3)->create();
        foreach ($movies as $movie) {
            MoviePerformer::factory()->create([
                'movie_id' => $movie->id,
                'performer_id' => $performer->id,
                'source_slug' => $performer->source_slug,
            ]);
        }

        $response = $this->getJson('/api/v1/performers/' . $performer->id, [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.linked_title_count', 3);
    }

    public function test_multi_performer_movie_links_back_to_all_ac55(): void
    {
        $first = Performer::factory()->create();
        $second = Performer::factory()->create();
        $movie = Movie::factory()->create();
        MoviePerformer::factory()->create([
            'movie_id' => $movie->id,
            'performer_id' => $first->id,
            'source_slug' => $first->source_slug,
        ]);
        MoviePerformer::factory()->create([
            'movie_id' => $movie->id,
            'performer_id' => $second->id,
            'source_slug' => $second->source_slug,
        ]);

        $firstResponse = $this->getJson('/api/v1/performers/' . $first->id, [
            'X-API-Key' => $this->apiKey,
        ]);
        $secondResponse = $this->getJson('/api/v1/performers/' . $second->id, [
            'X-API-Key' => $this->apiKey,
        ]);

        $firstResponse->assertOk()->assertJsonPath('data.linked_title_count', 1);
        $secondResponse->assertOk()->assertJsonPath('data.linked_title_count', 1);
    }

    public function test_show_returns_adr_shape(): void
    {
        $performer = Performer::factory()->create(['profile_url' => fake()->url(), 'image_url' => fake()->imageUrl()]);

        $response = $this->getJson('/api/v1/performers/' . $performer->id, [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'uuid',
                'id',
                'name_romaji',
                'name_kanji',
                'name_kana',
                'aliases',
                'birth_date',
                'height_cm',
                'bust',
                'waist',
                'hip',
                'cup',
                'blood_type',
                'bio_text',
                'debut_date',
                'location',
                'attrs',
                'linked_title_count',
                'profile_url',
                'image_url',
            ]]);
    }

    public function test_index_returns_summary_shape(): void
    {
        Performer::factory()->create([
            'bust' => 90,
            'bio_text' => fake()->sentence(),
        ]);

        $response = $this->getJson('/api/v1/performers', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => [[
                'uuid',
                'id',
                'name_romaji',
                'name_kanji',
                'name_kana',
                'aliases',
                'image_url',
                'profile_url',
            ]]]);

        $row = $response->json('data.0');
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('bust', $row);
        $this->assertArrayNotHasKey('bio_text', $row);
        $this->assertArrayNotHasKey('linked_title_count', $row);
    }

    public function test_show_unknown_id_returns_performer_not_found(): void
    {
        $response = $this->getJson('/api/v1/performers/999999', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('type', 'performer_not_found');
    }

    public function test_search_paginates_with_per_page(): void
    {
        Performer::factory()->count(5)->create();

        $response = $this->getJson('/api/v1/performers?per_page=2', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.last_page', 3);
    }

    public function test_search_without_q_returns_all_performers(): void
    {
        $performers = Performer::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/performers', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 3);

        $ids = collect($response->json('data'))->pluck('id')->all();
        foreach ($performers as $performer) {
            $this->assertContains($performer->id, $ids);
        }
    }

    public function test_q_shorter_than_two_chars_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/performers?q=a', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_per_page_above_100_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/performers?per_page=101', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }
    public function test_count_sort_cursor_page_two_keeps_ties_and_zero_counts(): void
    {
        $performers = Performer::factory()->count(4)->create(['canonical_performer_id' => null]);
        foreach ($performers->take(2) as $performer) {
            MoviePerformer::factory()->create(['movie_id' => Movie::factory()->create()->id, 'performer_id' => $performer->id]);
        }
        $headers = ['X-API-Key' => $this->apiKey];
        $first = $this->getJson('/api/v1/performers?sort=linked_title_count&per_page=1', $headers)->assertOk();
        $cursor = $first->json('meta.pagination.next_cursor');
        $second = $this->getJson('/api/v1/performers?sort=linked_title_count&per_page=1&cursor=' . urlencode($cursor), $headers)->assertOk();
        $third = $this->getJson('/api/v1/performers?sort=linked_title_count&per_page=2&cursor=' . urlencode($second->json('meta.pagination.next_cursor')), $headers)->assertOk();
        $this->assertSame([$performers[1]->id, $performers[0]->id, $performers[3]->id, $performers[2]->id], array_merge(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id'), array_column($third->json('data'), 'id')));
        $third->assertJsonPath('meta.pagination.has_more', false)->assertJsonPath('meta.pagination.total', 4);
    }
}

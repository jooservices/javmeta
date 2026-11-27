<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use App\Services\Api\V2\Catalogue\StoredMovieViewCounter;
use App\Services\Api\V2\Contracts\MovieViewCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** The parts of the catalogue detail operations that never reach the PostgreSQL read models: routing, scope, validation, problem codes. */
final class CatalogueDetailsTest extends V2TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_kinds_are_public_and_name_their_target_and_filter(): void
    {
        $response = $this->getJson('/api/v2/catalogs')->assertOk();

        self::assertSame(['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood-types', 'locations'], array_column($response->json('data'), 'kind'));
        $response->assertJsonPath('data.4', ['kind' => 'years', 'target' => 'movies', 'filter' => 'released_from'])->assertJsonPath('data.7', ['kind' => 'locations', 'target' => 'performers', 'filter' => 'location']);

        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v2/catalogs')->assertOk();
    }

    public function test_an_unknown_catalogue_is_404_on_every_catalogue_route(): void
    {
        foreach (['/api/v2/catalogs/nope', '/api/v2/catalogs/nope/index', '/api/v2/catalogs/nope/some-entry'] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'catalog_not_found');
        }
    }

    public function test_catalogue_and_photo_parameters_outside_the_contract_are_422_with_field_errors(): void
    {
        $this->getJson('/api/v2/catalogs/genres?script=klingon')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonFragment(['field' => 'script', 'code' => 'in']);
        $this->getJson('/api/v2/catalogs/genres?sort=popular')->assertStatus(422)->assertJsonFragment(['field' => 'sort']);
        $this->getJson('/api/v2/catalogs/genres?min_count=many')->assertStatus(422)->assertJsonFragment(['field' => 'min_count']);
        $this->getJson('/api/v2/photos?kind=banner')->assertStatus(422)->assertJsonFragment(['field' => 'kind']);
    }

    public function test_lookups_need_a_non_empty_list_and_no_csrf_token_for_a_signed_in_user(): void
    {
        $this->postJson('/api/v2/movies/lookups', [])->assertStatus(422)->assertJsonFragment(['field' => 'codes', 'code' => 'required']);
        $this->postJson('/api/v2/movies/lookups', ['codes' => array_fill(0, 501, 'AAA-001')])->assertStatus(422)->assertJsonFragment(['field' => 'codes']);
        $this->postJson('/api/v2/performers/lookups', ['ids' => []])->assertStatus(422)->assertJsonFragment(['field' => 'ids']);
        $this->postJson('/api/v2/performers/lookups', ['ids' => ['abc']])->assertStatus(422)->assertJsonFragment(['field' => 'ids.0']);

        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/v2/movies/lookups', [])->assertStatus(422);
    }

    public function test_views_are_counted_per_code_and_the_codes_are_required(): void
    {
        $counter = $this->app->make(MovieViewCounter::class);
        self::assertInstanceOf(StoredMovieViewCounter::class, $counter);
        self::assertSame(1, $counter->record('AAA-001', null));
        self::assertSame(2, $counter->record('AAA-001', 7));

        $this->getJson('/api/v2/movies/views?codes=AAA-001, BBB-002')->assertOk()->assertExactJson(['data' => ['AAA-001' => 2, 'BBB-002' => 0], 'meta' => []]);
        $this->getJson('/api/v2/movies/views?codes=,')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/movies/views')->assertStatus(422)->assertJsonFragment(['field' => 'codes', 'code' => 'required']);
    }

    public function test_an_unknown_facet_is_a_400_with_the_invalid_facet_code(): void
    {
        $this->getJson('/api/v2/movies/facets/values?facet=color')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
        $this->getJson('/api/v2/movies/facets/values')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
        $this->getJson('/api/v2/performers/facets/values?facet=height')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
    }

    public function test_a_performer_reference_without_a_number_is_404_on_every_performer_route(): void
    {
        foreach (['', '/sources', '/photos', '/co-performers', '/statistics', '/movies'] as $suffix) {
            $this->getJson("/api/v2/performers/nobody{$suffix}")->assertNotFound()->assertJsonPath('code', 'performer_not_found');
        }
    }

    public function test_performer_movies_only_take_the_sort_keys_of_the_contract(): void
    {
        $this->getJson('/api/v2/performers/1/movies?sort=popularity')->assertStatus(422)->assertJsonFragment(['field' => 'sort', 'code' => 'in']);
    }
}

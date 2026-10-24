<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Services\Movies\MovieLookupService;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CalendarRelatedEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-API-Key' => app(ApiKeyService::class)->create('calendar-test')->plaintext];
    }

    public function test_the_calendar_groups_a_month_by_day_and_validates_the_month(): void
    {
        $this->getJson('/api/v1/movies/calendar?month=2026-10')->assertUnauthorized();
        $this->getJson('/api/v1/movies/calendar?month=2026-13', $this->headers())->assertStatus(400)->assertJsonPath('type', 'invalid_filter');

        Movie::factory()->count(2)->create(['release_date' => '2026-10-05']);
        Movie::factory()->create(['release_date' => '2026-10-31']);
        Movie::factory()->create(['release_date' => '2026-11-01']);

        $r = $this->getJson('/api/v1/movies/calendar?month=2026-10', $this->headers())->assertOk();
        $this->assertSame(['2026-10-05', '2026-10-31'], array_column($r->json('data'), 'date'));
        $this->assertSame([2, 1], array_column($r->json('data'), 'count'));
        $this->assertSame(3, $r->json('meta.total'));
    }

    public function test_related_movies_share_series_or_maker_and_exclude_the_movie(): void
    {
        $make = function (string $code, ?string $series, string $maker): Movie {
            $movie = Movie::factory()->create(['display_code' => $code, 'series' => $series, 'maker' => $maker]);
            MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => $code, 'code_normalized' => app(MovieLookupService::class)->normalize($code)->value(), 'kind' => MovieCode::KIND_DVD]);

            return $movie;
        };
        $me = $make('REL-001', 'Same', 'M1');
        $make('REL-002', 'Same', 'M2');
        $make('REL-003', null, 'M1');
        $make('OTH-001', null, 'Else');

        $codes = array_column($this->getJson('/api/v1/movies/REL-001/related', $this->headers())->assertOk()->json('data'), 'code');
        sort($codes);
        $this->assertSame(['REL-002', 'REL-003'], $codes);
        $this->assertNotContains($me->display_code, $codes);
        $this->getJson('/api/v1/movies/NOPE-999/related', $this->headers())->assertNotFound();
    }
}

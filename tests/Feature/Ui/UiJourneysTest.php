<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use App\Services\Dataset\SampleDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every journey of the UI, through the same HTTP calls the pages make, on the sample dataset: sign in, discover,
 * search and suggestions, browse and filter movies, open a movie and its related movies, performers and their bio hub,
 * catalogs, calendar, statistics, personal marks and follows, and the admin page. The page rendering itself is covered
 * by ui-tests/render-smoke.mjs.
 */
final class UiJourneysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SampleDataset::class)->load();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_administrator']);
    }

    public function test_the_ui_needs_a_signed_in_owner_and_serves_its_shell(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->getJson('/ui-api/movies')->assertStatus(401);
        $this->actingAs($this->owner())->get('/app')->assertOk()->assertSee('csrf-token', false);
        $this->assertFileExists(public_path('ui/app.css'), 'the web server serves the static assets');
    }

    public function test_discover_shows_the_counts_and_the_sources(): void
    {
        $this->actingAs($this->owner());
        $this->getJson('/ui-api/status/summary')->assertOk()->assertJsonPath('data.counts.movies', 520);
        $this->getJson('/ui-api/status/sources')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_search_suggestions_browse_filter_and_detail(): void
    {
        $this->actingAs($this->owner());

        $this->getJson('/ui-api/suggest?q=sample')->assertOk()->assertJsonStructure(['data' => ['movies', 'performers', 'genres', 'makers', 'series']]);
        $list = $this->getJson('/ui-api/movies?per_page=24')->assertOk();
        $this->assertCount(24, $list->json('data'));
        $this->assertGreaterThan(24, $list->json('meta.pagination.total'));

        $filtered = $this->getJson('/ui-api/movies?maker=' . urlencode('Sample Maker 03') . '&per_page=100')->assertOk();
        $this->assertNotEmpty($filtered->json('data'));
        foreach ($filtered->json('data') as $movie) {
            $this->assertSame('Sample Maker 03', $movie['maker']);
        }
        $this->assertNotEmpty($this->getJson('/ui-api/movies?genre=' . urlencode('Sample Genre 05'))->json('data'));
        $this->assertNotEmpty($this->getJson('/ui-api/movies?released_from=2023-06-01&released_to=2023-06-30')->json('data'));

        $this->getJson('/ui-api/movies/SMP-0005')->assertOk()->assertJsonPath('data.code', 'SMP-0005');
        $codes = array_column($this->getJson('/ui-api/movies/SMP-0005/related')->assertOk()->json('data'), 'code');
        $this->assertNotContains('SMP-0005', $codes);
        $this->assertNotEmpty($codes);
        $this->getJson('/ui-api/movies/NOPE-999')->assertNotFound();
    }

    public function test_performers_bio_hub_catalogs_calendar_and_statistics(): void
    {
        $this->actingAs($this->owner());

        $this->assertNotEmpty($this->getJson('/ui-api/performers?per_page=12')->assertOk()->json('data'));
        $this->assertNotEmpty($this->getJson('/ui-api/performers?blood_type=O')->json('data'));
        $performer = $this->getJson('/ui-api/performers?q=' . urlencode('Aiko'))->json('data.0.id');
        $this->getJson('/ui-api/performers/' . $performer)->assertOk()->assertJsonStructure(['data' => ['name_romaji']]);

        foreach (['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood_types', 'locations'] as $kind) {
            $this->assertNotEmpty($this->getJson('/ui-api/meta/catalog/' . $kind)->assertOk()->json('data'), $kind);
        }

        $calendar = $this->getJson('/ui-api/movies/calendar?month=2023-03')->assertOk();
        $this->assertGreaterThan(0, $calendar->json('meta.total'));
        $this->assertNotContains('SMP-0001', array_merge(...array_map(static fn(array $d): array => array_column($d['movies'], 'code'), $calendar->json('data'))), 'a delisted movie is not on the calendar');
    }

    public function test_marks_follows_feed_and_admin(): void
    {
        $this->actingAs($this->owner());

        $this->postJson('/ui-me/marks', ['kind' => 'favourite', 'subject' => 'movie', 'key' => 'SMP-0007', 'on' => true])->assertOk();
        $this->postJson('/ui-me/marks', ['kind' => 'seen', 'subject' => 'movie', 'key' => 'SMP-0008', 'on' => true])->assertOk();
        $this->assertSame(['SMP-0007'], array_column($this->getJson('/ui-me/movies/favourite')->json('data'), 'code'));
        $this->assertSame(['SMP-0008'], array_column($this->getJson('/ui-me/movies/seen')->json('data'), 'code'));

        $this->postJson('/ui-me/marks', ['kind' => 'follow', 'subject' => 'maker', 'key' => 'Sample Maker 03', 'on' => true])->assertOk();
        $this->getJson('/ui-me/feed')->assertOk()->assertJsonPath('meta.follows', 1);
        $this->postJson('/ui-me/feed/seen')->assertOk();

        $this->getJson('/ui-admin/keys')->assertOk();
        $this->getJson('/ui-admin/report/quality')->assertOk()->assertJsonPath('data.movies', 520);
        $created = $this->postJson('/ui-admin/keys', ['label' => 'journey', 'scopes' => ['catalog']])->assertCreated();
        $this->deleteJson('/ui-admin/keys/' . $created->json('data.id'))->assertNoContent();
    }
}

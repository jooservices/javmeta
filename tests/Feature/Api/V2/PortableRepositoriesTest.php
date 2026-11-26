<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\Source;
use App\Repositories\Api\V2\CatalogueCountsRepository;
use App\Repositories\Api\V2\MeSettingsRepository;
use App\Repositories\Api\V2\SourceOpsRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The repositories of the v2 API whose queries are plain DB::table calls, run on SQLite with the real migrations. */
final class PortableRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_catalogue_counts_fall_back_to_the_catalogue_tables_when_there_are_no_search_tables(): void
    {
        $repository = new CatalogueCountsRepository();

        $this->assertSame(0, $repository->movies());
        $this->assertSame(0, $repository->performers());
    }

    public function test_catalogue_counts_prefer_the_search_tables_and_skip_delisted_movies(): void
    {
        Schema::create('rm_movies', static function (Blueprint $table): void {
            $table->id();
            $table->boolean('delisted');
        });
        Schema::create('rm_performers', static function (Blueprint $table): void {
            $table->id();
        });
        DB::table('rm_movies')->insert([['delisted' => false], ['delisted' => false], ['delisted' => true]]);
        DB::table('rm_performers')->insert([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
        $repository = new CatalogueCountsRepository();

        $this->assertSame(2, $repository->movies());
        $this->assertSame(4, $repository->performers());
    }

    public function test_preferences_are_saved_per_user_overwritten_and_read_back_in_key_order(): void
    {
        $repository = new MeSettingsRepository();

        $repository->savePreferences(1, ['theme' => 'dark', 'density' => ['rows' => 3]]);
        $repository->savePreferences(1, ['theme' => 'light']);
        $repository->savePreferences(2, ['theme' => 'sepia']);

        $this->assertSame(['density' => ['rows' => 3], 'theme' => 'light'], $repository->preferences(1));
        $this->assertSame(['theme' => 'sepia'], $repository->preferences(2));
        $this->assertSame([], $repository->preferences(3));
    }

    public function test_view_history_is_newest_first_filtered_by_kind_and_paged_with_the_full_total(): void
    {
        $repository = new MeSettingsRepository();
        Carbon::setTestNow('2024-05-01 10:00:00');
        $first = $repository->recordView(1, 'movie', 'ABP-001', 'First');
        Carbon::setTestNow('2024-05-01 11:00:00');
        $second = $repository->recordView(1, 'performer', '7', null);
        $third = $repository->recordView(1, 'movie', 'ABP-003', 'Third');
        $repository->recordView(2, 'movie', 'OTHER-1', 'Not mine');

        $all = $repository->history(1, null, 10, 0);
        $this->assertSame([$third, $second, $first], array_map(static fn(object $row): int => (int) $row->id, $all['rows']));
        $this->assertSame(3, $all['total']);

        $movies = $repository->history(1, 'movie', 10, 0);
        $this->assertSame(['ABP-003', 'ABP-001'], array_map(static fn(object $row): string => $row->ref, $movies['rows']));
        $this->assertSame(2, $movies['total']);

        $page = $repository->history(1, null, 1, 1);
        $this->assertSame([$second], array_map(static fn(object $row): int => (int) $row->id, $page['rows']));
        $this->assertSame(3, $page['total']);
    }

    public function test_clearing_the_history_only_removes_that_users_rows(): void
    {
        $repository = new MeSettingsRepository();
        $repository->recordView(1, 'movie', 'A', null);
        $repository->recordView(2, 'movie', 'B', null);

        $repository->clearHistory(1);

        $this->assertSame(0, $repository->history(1, null, 10, 0)['total']);
        $this->assertSame(1, $repository->history(2, null, 10, 0)['total']);
    }

    public function test_download_requests_are_counted_by_requester(): void
    {
        $repository = new MeSettingsRepository();
        foreach (['user:1', 'user:1', 'user:2', 'guest:abc'] as $requester) {
            DB::table('download_requests')->insert(['movie_code' => 'ABP-001', 'requester' => $requester, 'created_at' => now()]);
        }

        $this->assertSame(2, $repository->downloadRequests(1));
        $this->assertSame(0, $repository->downloadRequests(99));
    }

    public function test_download_requests_are_zero_without_the_table_or_without_the_requester_column(): void
    {
        $repository = new MeSettingsRepository();

        Schema::drop('download_requests');
        $this->assertSame(0, $repository->downloadRequests(1));

        Schema::create('download_requests', static function (Blueprint $table): void {
            $table->id();
            $table->string('movie_code');
        });
        $this->assertSame(0, $repository->downloadRequests(1));
    }

    public function test_sources_are_listed_by_priority_then_slug_and_found_by_slug(): void
    {
        Source::factory()->create(['slug' => 'b', 'priority' => 1]);
        Source::factory()->create(['slug' => 'a', 'priority' => 1]);
        Source::factory()->create(['slug' => 'c', 'priority' => 0]);
        $repository = new SourceOpsRepository();

        $this->assertSame(['c', 'a', 'b'], array_map(static fn(object $row): string => $row->slug, $repository->all()));
        $this->assertSame('b', $repository->find('b')?->slug);
        $this->assertNull($repository->find('missing'));
        $this->assertArrayHasKey('circuit_open_count', (array) $repository->find('b'));
        $this->assertArrayNotHasKey('soft404_markers', (array) $repository->find('b'));
    }

    public function test_claimed_jobs_and_recent_runs_are_counted_per_source(): void
    {
        DB::table('crawl_queue')->insert([
            ['source_slug' => 'a', 'url' => 'https://a/1', 'kind' => 'movie', 'status' => 'claimed'],
            ['source_slug' => 'a', 'url' => 'https://a/2', 'kind' => 'movie', 'status' => 'claimed'],
            ['source_slug' => 'a', 'url' => 'https://a/3', 'kind' => 'movie', 'status' => 'pending'],
            ['source_slug' => 'b', 'url' => 'https://b/1', 'kind' => 'movie', 'status' => 'claimed'],
        ]);
        DB::table('crawl_runs')->insert([
            ['source_slug' => 'a', 'started_at' => '2024-05-01 10:30:00', 'status' => 'ok'],
            ['source_slug' => 'a', 'started_at' => '2024-05-01 11:00:00', 'status' => 'ok'],
            ['source_slug' => 'b', 'started_at' => '2024-05-01 09:00:00', 'status' => 'ok'],
        ]);
        $repository = new SourceOpsRepository();

        $this->assertSame(['a' => 2, 'b' => 1], $repository->claimedBySource());
        $this->assertSame(['a' => 2], $repository->runsBySourceSince(Carbon::parse('2024-05-01 10:00:00')));
        $this->assertSame([], $repository->runsBySourceSince(Carbon::parse('2025-01-01')));
    }

    public function test_the_source_counts_split_enabled_and_circuit_state(): void
    {
        $repository = new SourceOpsRepository();
        $this->assertSame(['enabled' => 0, 'total' => 0, 'circuit_open' => 0, 'ok' => 0], $repository->counts());

        Source::factory()->create(['enabled' => true, 'circuit_state' => 'closed']);
        Source::factory()->create(['enabled' => true, 'circuit_state' => 'open']);
        Source::factory()->create(['enabled' => false, 'circuit_state' => 'closed']);
        Source::factory()->create(['enabled' => false, 'circuit_state' => 'half_open']);

        $this->assertSame(['enabled' => 2, 'total' => 4, 'circuit_open' => 2, 'ok' => 1], $repository->counts());
    }

    public function test_switching_a_source_writes_one_crawler_event_with_the_old_and_new_state(): void
    {
        Source::factory()->create(['slug' => 'a', 'enabled' => true]);
        $repository = new SourceOpsRepository();

        $this->assertTrue($repository->switchTo('a', false, 'owner@example.test'));

        $this->assertFalse((bool) DB::table('sources')->where('slug', 'a')->value('enabled'));
        $event = DB::table('crawl_events')->first();
        $this->assertSame('source_toggled', $event->kind);
        $this->assertSame('a', $event->source_slug);
        $this->assertNull($event->url);
        $this->assertSame(['from' => true, 'to' => false, 'by' => 'owner@example.test'], json_decode((string) $event->detail, true));
    }

    public function test_switching_a_source_to_the_state_it_already_has_changes_and_records_nothing(): void
    {
        Source::factory()->create(['slug' => 'a', 'enabled' => true]);
        $repository = new SourceOpsRepository();

        $this->assertTrue($repository->switchTo('a', true, 'someone'));

        $this->assertSame(0, DB::table('crawl_events')->count());
        $this->assertTrue((bool) DB::table('sources')->where('slug', 'a')->value('enabled'));
    }

    public function test_switching_an_unknown_source_answers_null_and_writes_nothing(): void
    {
        $repository = new SourceOpsRepository();

        $this->assertNull($repository->switchTo('missing', true, 'someone'));
        $this->assertSame(0, DB::table('crawl_events')->count());
    }
}

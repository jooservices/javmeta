<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_enqueues_listing_urls_for_selected_source(): void
    {
        $this->syncSources();

        $this->artisan('crawler:seed', ['--source' => 'javdb', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'javdb',
            'url' => 'https://javdb.com/',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
        $this->assertSame(1, CrawlRun::query()->where('source_slug', 'javdb')->count());
        $this->assertSame(1, CrawlEvent::query()->where('source_slug', 'javdb')->where('kind', 'queue_tick')->count());
    }

    public function test_seed_deduplicates_listing_urls_across_runs(): void
    {
        $this->syncSources();

        $this->artisan('crawler:seed', ['--source' => 'javdb', '--limit' => 1])->assertSuccessful();
        $this->artisan('crawler:seed', ['--source' => 'javdb', '--limit' => 1])->assertSuccessful();

        $this->assertSame(1, CrawlQueue::query()->where('source_slug', 'javdb')->where('url', 'https://javdb.com/')->count());
    }

    public function test_a_finished_listing_starts_again_from_page_one_after_the_pause(): void
    {
        config()->set('javmeta_sources.defaults.loop.pause_seconds', 120);
        $this->syncSources();
        $seed = $this->row('javdb', 'https://javdb.com/', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_DONE);
        $page2 = $this->row('javdb', 'https://javdb.com/?page=2', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_DONE);
        $detail = $this->row('javdb', 'https://javdb.com/v/abc', CrawlQueue::KIND_DETAIL, CrawlQueue::STATUS_DONE);

        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();

        $seed->refresh();
        $this->assertSame(CrawlQueue::STATUS_PENDING, $seed->status);
        $this->assertSame(0, $seed->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(120)->getTimestamp(), $seed->next_attempt_at?->getTimestamp(), 3);
        $this->assertNull(CrawlQueue::query()->find($page2->id));
        $this->assertSame(CrawlQueue::STATUS_DONE, $detail->refresh()->status);
    }

    public function test_a_listing_that_still_has_pages_to_read_is_not_restarted(): void
    {
        $this->syncSources();
        $seed = $this->row('javdb', 'https://javdb.com/', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_DONE);
        $seed->forceFill(['next_attempt_at' => now()->addMinutes(60)])->save();
        $page2 = $this->row('javdb', 'https://javdb.com/?page=2', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_PENDING);

        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_DONE, $seed->refresh()->status);
        $this->assertNotNull(CrawlQueue::query()->find($page2->id));
    }

    public function test_page_one_is_refreshed_on_schedule_while_the_listing_chain_continues(): void
    {
        config()->set('javmeta_sources.defaults.loop.fresh_interval_minutes', 60);
        $start = now()->startOfMinute();
        $this->travelTo($start);
        $this->syncSources();
        $seed = $this->row('javdb', 'https://javdb.com/', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_DONE);
        $seed->forceFill(['next_attempt_at' => $start->copy()->addMinutes(60)])->save();
        $page2 = $this->row('javdb', 'https://javdb.com/?page=2', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_PENDING);
        $detail = $this->row('javdb', 'https://javdb.com/v/abc', CrawlQueue::KIND_DETAIL, CrawlQueue::STATUS_DONE);

        $this->travelTo($start->copy()->addMinutes(59));
        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();
        $this->assertSame(CrawlQueue::STATUS_DONE, $seed->refresh()->status);

        $this->travelTo($start->copy()->addMinutes(60));
        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $seed->refresh()->status);
        $this->assertSame(0, $seed->attempts);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $page2->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_DONE, $detail->refresh()->status);

        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();

        $this->assertSame(1, CrawlQueue::query()->where('source_slug', 'javdb')->where('url', 'https://javdb.com/')->count());
        $this->assertSame(1, CrawlQueue::query()->where('source_slug', 'javdb')->where('url', 'https://javdb.com/v/abc')->count());
        $this->assertSame(CrawlQueue::STATUS_PENDING, $seed->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $page2->refresh()->status);
    }

    public function test_performer_page_one_is_refreshed_on_schedule(): void
    {
        config()->set('javmeta_sources.defaults.loop.fresh_interval_minutes', 15);
        $start = now()->startOfMinute();
        $this->travelTo($start);
        $this->syncSources();
        $seedUrl = 'https://www.javlibrary.com/en/star_list.php';
        $seed = $this->row('javlibrary', $seedUrl, CrawlQueue::KIND_PERFORMER_LISTING, CrawlQueue::STATUS_DONE);
        $seed->forceFill(['next_attempt_at' => $start->copy()->addMinutes(15)])->save();
        $page2 = $this->row('javlibrary', 'https://www.javlibrary.com/en/star_list.php?page=2', CrawlQueue::KIND_PERFORMER_LISTING, CrawlQueue::STATUS_PENDING);

        $this->travelTo($start->copy()->addMinutes(15));
        $this->artisan('crawler:seed', ['--source' => 'javlibrary'])->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $seed->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $page2->refresh()->status);
        $this->assertSame(1, CrawlQueue::query()->where('source_slug', 'javlibrary')->where('url', $seedUrl)->count());
    }

    public function test_failed_page_one_retries_after_the_fresh_interval_while_later_pages_are_pending(): void
    {
        config()->set('javmeta_sources.defaults.loop.fresh_interval_minutes', 10);
        $start = now()->startOfMinute();
        $this->travelTo($start);
        $this->syncSources();
        $seed = $this->row('javdb', 'https://javdb.com/', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_FAILED);
        $seed->forceFill(['last_failed_at' => $start])->save();
        $page2 = $this->row('javdb', 'https://javdb.com/?page=2', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_PENDING);

        $this->travelTo($start->copy()->addMinutes(9));
        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();
        $this->assertSame(CrawlQueue::STATUS_FAILED, $seed->refresh()->status);

        $this->travelTo($start->copy()->addMinutes(10));
        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $seed->refresh()->status);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $page2->refresh()->status);
    }

    public function test_a_failed_seed_page_is_tried_again_when_the_listing_is_finished(): void
    {
        $this->syncSources();
        $seed = $this->row('javdb', 'https://javdb.com/', CrawlQueue::KIND_LISTING, CrawlQueue::STATUS_FAILED);

        $this->artisan('crawler:seed', ['--source' => 'javdb'])->assertSuccessful();

        $this->assertSame(CrawlQueue::STATUS_PENDING, $seed->refresh()->status);
    }

    private function row(string $slug, string $url, string $kind, string $status): CrawlQueue
    {
        return CrawlQueue::query()->create([
            'source_slug' => $slug,
            'url' => $url,
            'kind' => $kind,
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);
    }

    public function test_seed_enqueues_performer_listing_for_warashi(): void
    {
        $this->syncSources();

        $this->artisan('crawler:seed', ['--source' => 'warashi', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'warashi',
            'url' => 'https://warashi-asian-pornstars.fr/en/s-2-2/female-pornstars/toutes/all/page/1',
            'kind' => CrawlQueue::KIND_PERFORMER_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
    }

    public function test_seed_enqueues_gallery_for_eporner(): void
    {
        $this->syncSources();

        $this->artisan('crawler:seed', ['--source' => 'eporner', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'eporner',
            'kind' => CrawlQueue::KIND_GALLERY,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
    }

    public function test_seed_enqueues_gallery_listing_urls_as_generic_listing_rows(): void
    {
        $this->syncSources();

        $this->artisan('crawler:seed', ['--source' => 'javphotos', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'javphotos',
            'url' => 'https://jav.photos/free/',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
    }

    public function test_seed_splits_xcity_movie_and_performer_seeds(): void
    {
        $this->syncSources();

        $this->artisan('crawler:seed', ['--source' => 'xcity', '--limit' => 2])->assertSuccessful();

        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'xcity',
            'url' => 'https://xxx.xcity.jp/avod/list/?style=simple',
            'kind' => CrawlQueue::KIND_LISTING,
        ]);
        $this->assertDatabaseHas('crawl_queue', [
            'source_slug' => 'xcity',
            'url' => 'https://xxx.xcity.jp/idol/',
            'kind' => CrawlQueue::KIND_PERFORMER_LISTING,
        ]);
    }

    private function syncSources(): void
    {
        $this->artisan('crawler:sync-sources')->assertSuccessful();
    }
}

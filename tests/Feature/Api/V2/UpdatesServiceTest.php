<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Illuminate\Support\Carbon;
use Tests\Support\Api\V2\FakesPostgresRows;
use Tests\TestCase;

/** Updates by day, the summary, highlights and the release calendar with canned PostgreSQL rows. */
final class UpdatesServiceTest extends TestCase
{
    use FakesPostgresRows;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recent_movies_are_grouped_under_their_collection_day_in_order(): void
    {
        $this->fakeRows([
            'count(*) AS n FROM rm_movies m' => [['n' => 3]],
            'm.crawled_at IS NOT NULL ORDER BY' => [
                $this->movieRow(['code' => 'A-1', 'day' => '2024-05-02']),
                $this->movieRow(['code' => 'A-2', 'day' => '2024-05-02']),
                $this->movieRow(['code' => 'A-3', 'day' => '2024-05-01']),
            ],
        ]);

        $response = $this->getJson('/api/v2/updates?per_page=3');

        $response->assertOk()
            ->assertJsonPath('data.0.day', '2024-05-02')
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.0.items.1.code', 'A-2')
            ->assertJsonPath('data.1.day', '2024-05-01')
            ->assertJsonPath('data.1.items.0.code', 'A-3')
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 3, 'total' => 3, 'pages' => 1]);
    }

    public function test_the_updates_page_and_size_become_limit_and_offset_and_pages_are_counted(): void
    {
        $this->fakeRows(['count(*) AS n FROM rm_movies m' => [['n' => 250]], 'm.crawled_at IS NOT NULL ORDER BY' => []]);

        $this->getJson('/api/v2/updates?page=2&per_page=1000')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.pagination.pages', 3)->assertJsonPath('meta.pagination.per_page', 120);

        $this->assertStringContainsString('LIMIT 120 OFFSET 120', $this->sqlContaining('ORDER BY m.crawled_at DESC, m.id DESC')[0]['sql']);
    }

    public function test_recent_performers_are_ordered_by_collection_or_debut_and_may_need_a_photo(): void
    {
        $this->fakeRows([
            'count(*) AS n FROM rm_performers p' => [['n' => 1]],
            'FROM rm_performers p WHERE' => [$this->performerRow(['day' => '2024-05-02'])],
        ]);

        $this->getJson('/api/v2/updates?type=performers')->assertOk()->assertJsonPath('data.0.items.0.slug', '7-yua-mikami');
        $this->getJson('/api/v2/updates?type=performers&sort=debut&has_photo=1')->assertOk();

        $this->assertStringContainsString('ORDER BY p.crawled_at DESC', $this->sqlContaining('ORDER BY p.')[0]['sql']);
        $this->assertStringContainsString('ORDER BY p.first_seen_at DESC', $this->sqlContaining('ORDER BY p.')[1]['sql']);
        $this->assertStringContainsString('p.first_seen_at IS NOT NULL AND p.photo_url IS NOT NULL', $this->sqlContaining('count(*) AS n FROM rm_performers p')[1]['sql']);
        $this->assertStringNotContainsString('photo_url', $this->sqlContaining('count(*) AS n FROM rm_performers p')[0]['sql']);
    }

    public function test_recent_photos_are_avatars_grouped_by_day(): void
    {
        $this->fakeRows([
            'count(*) AS n FROM rm_performers WHERE photo_url' => [['n' => 1]],
            'SELECT id, name_romaji, photo_url, (crawled_at' => [['id' => 7, 'name_romaji' => 'Yua Mikami', 'photo_url' => 'https://img/p.jpg', 'day' => '2024-05-02']],
        ]);

        $this->getJson('/api/v2/updates?type=photos')
            ->assertOk()
            ->assertJsonPath('data.0.items.0', ['kind' => 'avatar', 'thumb_url' => 'https://img/p.jpg', 'full_url' => 'https://img/p.jpg', 'label' => 'Yua Mikami', 'performer_id' => 7]);
    }

    public function test_an_unknown_updates_type_is_a_400_problem(): void
    {
        $this->fakeRows([]);

        $this->getJson('/api/v2/updates?type=episodes')->assertStatus(400)->assertJsonPath('code', 'invalid_type');
    }

    public function test_the_summary_counts_are_integers_and_cached(): void
    {
        $this->fakeRows(['AS photos_24h' => [['movies_24h' => '5', 'performers_24h' => '2', 'photos_24h' => '1', 'movies_7d' => '30', 'performers_7d' => '9']]]);

        $this->getJson('/api/v2/updates/summary')->assertOk()->assertJsonPath('data', ['movies_24h' => 5, 'performers_24h' => 2, 'photos_24h' => 1, 'movies_7d' => 30, 'performers_7d' => 9]);
        $this->getJson('/api/v2/updates/summary')->assertOk();

        $this->assertCount(1, $this->sqlContaining('AS photos_24h'));
    }

    public function test_highlights_combine_picks_hot_performers_and_the_newest_movies(): void
    {
        $this->fakeRows([
            'm.score IS NOT NULL AND m.release_date >' => [$this->movieRow(['code' => 'PICK-1'])],
            'c.n AS recent' => [$this->performerRow(['recent' => '6'])],
            'ORDER BY m.crawled_at DESC NULLS LAST' => [$this->movieRow(['code' => 'NEW-1']), $this->movieRow(['code' => 'NEW-2'])],
        ]);

        $this->getJson('/api/v2/highlights')
            ->assertOk()
            ->assertJsonPath('data.picks.0.code', 'PICK-1')
            ->assertJsonPath('data.hot_performers.0.name', 'Yua Mikami')
            ->assertJsonPath('data.hot_performers.0.recent_movies', 6)
            ->assertJsonPath('data.newest.1.code', 'NEW-2');
    }

    public function test_the_calendar_of_a_day_lists_that_days_movies(): void
    {
        $this->fakeRows(['m.release_date = ?::date' => [$this->movieRow(['code' => 'DAY-1'])]]);

        $content = (string) $this->getJson('/api/v2/calendar?day=2024-05-01')->assertOk()->assertJsonPath('data.0.code', 'DAY-1')->getContent();

        $this->assertStringContainsString('"meta":{}', $content);
        $this->assertSame(['2024-05-01'], $this->sqlCalls[0]['bindings']);
    }

    public function test_the_calendar_of_a_month_counts_releases_per_day(): void
    {
        $this->fakeRows(["to_char(release_date, 'YYYY-MM-DD') AS day" => [['day' => '2024-05-01', 'count' => '3'], ['day' => '2024-05-09', 'count' => 1]]]);

        $this->getJson('/api/v2/calendar?month=2024-05')
            ->assertOk()
            ->assertJsonPath('data', [['day' => '2024-05-01', 'count' => 3], ['day' => '2024-05-09', 'count' => 1]])
            ->assertJsonPath('meta.month', '2024-05');
        $this->assertSame(['2024-05', '2024-05'], $this->sqlCalls[0]['bindings']);
    }

    public function test_the_calendar_defaults_to_the_current_utc_month_and_treats_an_empty_day_as_no_day(): void
    {
        Carbon::setTestNow('2024-12-31 23:30:00');
        $this->fakeRows(["to_char(release_date, 'YYYY-MM-DD') AS day" => []]);

        $this->getJson('/api/v2/calendar')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.month', '2024-12');
        $this->getJson('/api/v2/calendar?day=&month=')->assertOk()->assertJsonPath('meta.month', '2024-12');
    }
}

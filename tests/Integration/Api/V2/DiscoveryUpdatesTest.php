<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

/** Recent updates, summary, highlights and the release calendar on real PostgreSQL. */
final class DiscoveryUpdatesTest extends DiscoveryTestCase
{
    public function test_movie_updates_are_grouped_by_the_utc_day_they_were_collected(): void
    {
        $response = $this->getJson('/api/v2/updates')->assertOk();

        $response->assertJsonPath('meta.pagination.total', 2);
        $codes = [];

        foreach ($response->json('data') as $group) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $group['day']);
            self::assertSame(count($group['items']), $group['total']);
            $codes = [...$codes, ...array_column($group['items'], 'code')];
        }

        self::assertSame(['AAA-001', 'BBB-002'], $codes);
    }

    public function test_performer_updates_sort_by_crawl_or_debut_and_can_require_a_photo(): void
    {
        $byCrawl = $this->getJson('/api/v2/updates?type=performers')->assertOk();
        self::assertSame([1, 2], array_column(array_merge(...array_column($byCrawl->json('data'), 'items')), 'id'));

        $photoOnly = $this->getJson('/api/v2/updates?type=performers&sort=debut&has_photo=1')->assertOk();
        self::assertSame([1], array_column(array_merge(...array_column($photoOnly->json('data'), 'items')), 'id'));
    }

    public function test_photo_updates_list_new_avatars(): void
    {
        $response = $this->getJson('/api/v2/updates?type=photos&per_page=5')->assertOk();

        $response->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.items.0.kind', 'avatar')->assertJsonPath('data.0.items.0.performer_id', 1);
    }

    public function test_an_unknown_updates_type_is_a_bad_request(): void
    {
        $this->getJson('/api/v2/updates?type=episodes')->assertStatus(400)->assertJsonPath('code', 'invalid_type');
    }

    public function test_the_summary_counts_what_was_first_seen_in_the_last_day_and_week(): void
    {
        $this->getJson('/api/v2/updates/summary')->assertOk()->assertExactJson(['data' => ['movies_24h' => 1, 'performers_24h' => 1, 'photos_24h' => 1, 'movies_7d' => 2, 'performers_7d' => 2], 'meta' => []]);
    }

    public function test_highlights_have_picks_hot_performers_and_the_newest_movies(): void
    {
        $this->movie(10, 'NEW-010', 'Fresh pick', ['release_date' => now('UTC')->subDays(5)->toDateString(), 'year' => (int) now('UTC')->year, 'score' => 9.5, 'cover_url' => 'https://img.test/n.jpg', 'crawled_at' => now('UTC'), 'performer_ids' => '{1}']);

        $response = $this->getJson('/api/v2/highlights')->assertOk();

        $response->assertJsonPath('data.picks.0.code', 'NEW-010')->assertJsonPath('data.hot_performers.0.id', 1)->assertJsonPath('data.hot_performers.0.recent_movies', 1)->assertJsonPath('data.newest.0.code', 'NEW-010');
    }

    public function test_the_calendar_counts_releases_per_day_of_a_month_and_lists_one_day(): void
    {
        $this->getJson('/api/v2/calendar?month=2025-03')->assertOk()->assertExactJson(['data' => [['day' => '2025-03-01', 'count' => 2]], 'meta' => ['month' => '2025-03']]);

        $day = $this->getJson('/api/v2/calendar?day=2025-03-01')->assertOk();
        self::assertSame(['AAA-001', 'BBB-002'], array_column($day->json('data'), 'code'));
        $this->getJson('/api/v2/calendar?day=2025-03-02')->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_calendar_defaults_to_the_current_month_and_checks_its_parameters(): void
    {
        $this->getJson('/api/v2/calendar')->assertOk()->assertJsonPath('meta.month', now('UTC')->format('Y-m'));
        $this->getJson('/api/v2/calendar?month=2025-13')->assertStatus(422)->assertJsonPath('errors.0.field', 'month');
        $this->getJson('/api/v2/calendar?day=2025-02-31')->assertStatus(422)->assertJsonPath('errors.0.field', 'day');
    }
}

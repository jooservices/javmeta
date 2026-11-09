<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UiMarksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_the_owner_session(): void
    {
        $this->getJson('/ui-me/marks')->assertStatus(401);
        $this->postJson('/ui-me/marks', [])->assertStatus(401);
    }

    public function test_marks_toggle_and_list_movies(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        Movie::factory()->create(['display_code' => 'MRK-001']);

        $this->postJson('/ui-me/marks', ['kind' => 'favourite', 'subject' => 'movie', 'key' => 'MRK-001', 'on' => true])->assertOk();
        $this->postJson('/ui-me/marks', ['kind' => 'favourite', 'subject' => 'movie', 'key' => 'MRK-001', 'on' => true])->assertOk();
        $this->assertCount(1, $this->getJson('/ui-me/marks?kind=favourite')->json('data'));
        $this->assertSame(['MRK-001'], array_column($this->getJson('/ui-me/movies/favourite')->assertOk()->json('data'), 'code'));
        $this->getJson('/ui-me/movies/seen')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/ui-me/movies/bogus')->assertNotFound();

        $this->postJson('/ui-me/marks', ['kind' => 'favourite', 'subject' => 'movie', 'key' => 'MRK-001', 'on' => false])->assertOk();
        $this->assertCount(0, $this->getJson('/ui-me/marks')->json('data'));
        $this->postJson('/ui-me/marks', ['kind' => 'follow', 'subject' => 'movie', 'key' => 'x', 'on' => true])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This mark does not apply to that subject.']);
        $this->postJson('/ui-me/marks', ['kind' => 'nope', 'subject' => 'movie', 'key' => 'x', 'on' => true])->assertStatus(422);
    }

    public function test_movie_marks_skip_codes_without_a_catalog_movie_but_keep_the_mark_total(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $this->postJson('/ui-me/marks', ['kind' => 'favourite', 'subject' => 'movie', 'key' => 'MISSING-001', 'on' => true])->assertOk();

        $this->getJson('/ui-me/movies/favourite')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_ui_marks_movies_paginates_with_a_hard_max(): void
    {
        $user = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($user);
        $movies = Movie::factory()->count(201)->create();
        $timestamp = now()->toDateTimeString();
        DB::table('user_marks')->insert($movies->map(static fn(Movie $movie): array => [
            'user_id' => $user->id,
            'kind' => 'favourite',
            'subject' => 'movie',
            'key' => $movie->display_code,
            'created_at' => $timestamp,
        ])->all());

        $this->getJson('/ui-me/movies/favourite?per_page=500')->assertStatus(422);
        $first = $this->getJson('/ui-me/movies/favourite?per_page=200&page=1')->assertOk()
            ->assertJsonCount(200, 'data')->assertJsonPath('meta.total', 201)->assertJsonPath('meta.pagination.last_page', 2);
        $second = $this->getJson('/ui-me/movies/favourite?per_page=200&page=2')->assertOk()->assertJsonCount(1, 'data');

        self::assertNotSame($first->json('data.0.code'), $second->json('data.0.code'));
    }

    public function test_the_feed_shows_new_movies_of_follows_and_remembers_the_last_visit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        Movie::factory()->create(['display_code' => 'FED-001', 'maker' => 'Followed', 'first_seen_at' => now()->subDay()]);
        Movie::factory()->create(['display_code' => 'FED-002', 'maker' => 'Followed', 'first_seen_at' => now()->subDays(60)]);
        Movie::factory()->create(['display_code' => 'OTH-001', 'maker' => 'Other', 'first_seen_at' => now()->subDay()]);
        Movie::factory()->create(['display_code' => 'FED-DELISTED', 'maker' => 'Followed', 'first_seen_at' => now()->subDay(), 'delisted_at' => now()]);

        $this->getJson('/ui-me/feed')->assertOk()->assertJsonPath('meta.follows', 0)->assertJsonPath('data', []);
        $this->postJson('/ui-me/marks', ['kind' => 'follow', 'subject' => 'maker', 'key' => 'Followed', 'on' => true])->assertOk();

        $feed = $this->getJson('/ui-me/feed')->assertOk();
        $this->assertSame(['FED-001'], array_column($feed->json('data'), 'code'));
        $this->assertSame(1, $feed->json('meta.new_since_last_visit'));

        $this->postJson('/ui-me/feed/seen')->assertOk();
        $this->assertSame(0, $this->getJson('/ui-me/feed')->json('meta.new_since_last_visit'));
    }

    public function test_the_feed_includes_movies_linked_to_followed_performers_and_series(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $performer = Performer::factory()->create();
        $performerMovie = Movie::factory()->create([
            'display_code' => 'FED-PERFORMER',
            'series' => 'Unfollowed Series',
            'first_seen_at' => now()->subDay(),
        ]);
        DB::table('movie_performers')->insert([
            'movie_id' => $performerMovie->id,
            'performer_id' => $performer->id,
            'source_slug' => $performer->source_slug,
            'crawled_at' => now(),
        ]);
        Movie::factory()->create([
            'display_code' => 'FED-SERIES',
            'series' => 'Followed Series',
            'first_seen_at' => now()->subDay(),
        ]);
        $this->postJson('/ui-me/marks', [
            'kind' => 'follow',
            'subject' => 'performer',
            'key' => (string) $performer->id,
            'on' => true,
        ])->assertOk();
        $this->postJson('/ui-me/marks', [
            'kind' => 'follow',
            'subject' => 'series',
            'key' => 'Followed Series',
            'on' => true,
        ])->assertOk();

        $feed = $this->getJson('/ui-me/feed')->assertOk();

        self::assertEqualsCanonicalizing(['FED-PERFORMER', 'FED-SERIES'], array_column($feed->json('data'), 'code'));
        self::assertSame(2, $feed->json('meta.follows'));
        self::assertSame(2, $feed->json('meta.new_since_last_visit'));
    }

    public function test_saved_searches_replace_by_name_and_are_scoped_to_owner_and_browser(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $other = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner);
        $save = static fn(string $subject, string $query): array => [
            'kind' => 'saved_search', 'subject' => $subject,
            'key' => json_encode(['name' => 'Đóng chung <b>x</b>', 'query' => $query], JSON_THROW_ON_ERROR), 'on' => true,
        ];
        $this->postJson('/ui-me/marks', $save('movies', 'actress_id=1,2'))->assertOk();
        $this->postJson('/ui-me/marks', $save('movies', 'genre=Drama'))->assertOk();
        $this->postJson('/ui-me/marks', $save('performers', 'cup=D,E'))->assertOk();
        $rows = $this->getJson('/ui-me/marks?kind=saved_search&subject=movies')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('genre=Drama', json_decode($rows[0]['key'], true)['query']);
        $this->assertCount(1, $this->getJson('/ui-me/marks?subject=performers')->json('data'));
        $this->actingAs($other);
        $this->assertSame([], $this->getJson('/ui-me/marks?kind=saved_search')->json('data'));
        $this->postJson('/ui-me/marks', $save('movies', 'q=other'))->assertOk();
        $this->actingAs($owner);
        // A stale menu must not delete a newer replacement with the same name.
        $this->postJson('/ui-me/marks', [...$save('movies', 'actress_id=1,2'), 'on' => false])->assertOk();
        $this->assertCount(1, $this->getJson('/ui-me/marks?subject=movies')->json('data'));
        $this->postJson('/ui-me/marks', [...$save('movies', 'genre=Drama'), 'on' => false])->assertOk();
        $this->assertCount(0, $this->getJson('/ui-me/marks?subject=movies')->json('data'));
        $this->actingAs($other);
        $this->assertCount(1, $this->getJson('/ui-me/marks?subject=movies')->json('data'));
    }

    public function test_saved_search_validation_and_limits_do_not_weaken_other_marks(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $mark = ['kind' => 'saved_search', 'subject' => 'movies', 'on' => true];
        $this->postJson('/ui-me/marks', [...$mark, 'key' => 'not json'])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'A saved search requires a name (1–80 characters) and a query (up to 1500 characters).']);
        foreach (['null', '[]', '{}', '{"name":" ","query":""}', '{"name":12,"query":""}', '{"name":"x","query":[]}', '{"name":"x"}'] as $key) {
            $this->postJson('/ui-me/marks', [...$mark, 'key' => $key])->assertUnprocessable();
        }
        foreach ([['name' => str_repeat('a', 81), 'query' => ''], ['name' => 'x', 'query' => str_repeat('a', 1501)]] as $value) {
            $this->postJson('/ui-me/marks', [...$mark, 'key' => json_encode($value)])->assertUnprocessable();
        }
        $key = json_encode(['name' => str_repeat('ệ', 80), 'query' => 'q=' . str_repeat('a', 1498)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->postJson('/ui-me/marks', [...$mark, 'key' => $key])->assertOk();
        $this->postJson('/ui-me/marks', [...$mark, 'key' => '{"name":"All","query":""}'])->assertOk();
        $this->postJson('/ui-me/marks', [...$mark, 'key' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->postJson('/ui-me/marks', [...$mark, 'subject' => 'movie', 'key' => $key])->assertUnprocessable();
        $this->postJson('/ui-me/marks', ['kind' => 'follow', 'subject' => 'maker', 'key' => str_repeat('a', 301), 'on' => true])->assertUnprocessable();
        $this->assertCount(2, $this->getJson('/ui-me/marks?kind=saved_search')->json('data'));
    }
}

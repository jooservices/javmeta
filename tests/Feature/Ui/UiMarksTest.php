<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->postJson('/ui-me/marks', ['kind' => 'follow', 'subject' => 'movie', 'key' => 'x', 'on' => true])->assertStatus(422);
        $this->postJson('/ui-me/marks', ['kind' => 'nope', 'subject' => 'movie', 'key' => 'x', 'on' => true])->assertStatus(422);
    }

    public function test_the_feed_shows_new_movies_of_follows_and_remembers_the_last_visit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        Movie::factory()->create(['display_code' => 'FED-001', 'maker' => 'Followed', 'first_seen_at' => now()->subDay()]);
        Movie::factory()->create(['display_code' => 'FED-002', 'maker' => 'Followed', 'first_seen_at' => now()->subDays(60)]);
        Movie::factory()->create(['display_code' => 'OTH-001', 'maker' => 'Other', 'first_seen_at' => now()->subDay()]);

        $this->getJson('/ui-me/feed')->assertOk()->assertJsonPath('meta.follows', 0)->assertJsonPath('data', []);
        $this->postJson('/ui-me/marks', ['kind' => 'follow', 'subject' => 'maker', 'key' => 'Followed', 'on' => true])->assertOk();

        $feed = $this->getJson('/ui-me/feed')->assertOk();
        $this->assertSame(['FED-001'], array_column($feed->json('data'), 'code'));
        $this->assertSame(1, $feed->json('meta.new_since_last_visit'));

        $this->postJson('/ui-me/feed/seen')->assertOk();
        $this->assertSame(0, $this->getJson('/ui-me/feed')->json('meta.new_since_last_visit'));
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
        foreach (['not json', 'null', '[]', '{}', '{"name":" ","query":""}', '{"name":12,"query":""}', '{"name":"x","query":[]}', '{"name":"x"}'] as $key) {
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

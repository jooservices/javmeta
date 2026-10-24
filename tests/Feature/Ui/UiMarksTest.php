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
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The /me lists that read the PostgreSQL search tables: marked movies, followed performers and the follow feed. */
final class MeActivityTest extends PostgresApiTestCase
{
    /** @var array<string, string> */
    private array $csrf = [];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $star = Performer::factory()->create(['name_romaji' => 'Aoi Test']);
        $a = Movie::factory()->create(['display_code' => 'AAA-001', 'title_en' => 'First', 'maker' => 'Maker One', 'first_seen_at' => now()->subDays(3)]);
        $b = Movie::factory()->create(['display_code' => 'BBB-002', 'title_en' => 'Second', 'maker' => 'Maker Two', 'series' => 'Saga']);
        Movie::factory()->create(['display_code' => 'CCC-003', 'title_en' => 'Third', 'maker' => 'Maker Three']);
        MoviePerformer::factory()->create(['movie_id' => $a->id, 'performer_id' => $star->id]);
        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();
        DB::table('rm_movies')->where('code', 'AAA-001')->update(['crawled_at' => now()->subDays(2)]);
        DB::table('rm_movies')->where('code', 'BBB-002')->update(['crawled_at' => now()->subDay(), 'genres' => '{Drama}', 'label' => 'Label X']);
        DB::table('rm_movies')->where('code', 'CCC-003')->update(['crawled_at' => now()]);

        $this->user = User::factory()->create(['role' => 'user']);
        $this->actingAs($this->user, 'web')->getJson('/api/v2/sessions/current');
        $this->csrf = ['X-CSRF-Token' => (string) $this->app['session.store']->token()];
        self::assertNotNull($b->id);
        $this->performerId = (int) $star->id;
    }

    private int $performerId = 0;

    public function test_marked_movies_come_back_as_summaries_in_mark_order(): void
    {
        $this->putJson('/api/v2/me/marks/favorite/movie/AAA-001', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/marks/favorite/movie/BBB-002', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/marks/favorite/movie/GONE-999', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/marks/want/movie/CCC-003', [], $this->csrf)->assertOk();

        $response = $this->getJson('/api/v2/me/marks/movies')->assertOk()->assertJsonPath('meta.pagination.total', 3);
        self::assertSame(['BBB-002', 'AAA-001'], array_column($response->json('data'), 'code'));
        self::assertSame('Aoi Test', $response->json('data.1.performers.0.name'));

        $this->getJson('/api/v2/me/marks/movies?kind=want')->assertOk()->assertJsonPath('data.0.code', 'CCC-003');
        $this->getJson('/api/v2/me/marks/movies?kind=hidden')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/me')->assertJsonPath('data.counters.marks', 4);
    }

    public function test_followed_performers_carry_their_summary(): void
    {
        $this->putJson("/api/v2/me/follows/performer/{$this->performerId}", [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/follows/performer/not-a-number', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/follows/maker/Maker%20One', [], $this->csrf)->assertOk();

        $list = $this->getJson('/api/v2/me/follows')->assertOk()->assertJsonCount(3, 'data');
        $byRef = array_column($list->json('data'), null, 'ref');
        self::assertSame('Aoi Test', $byRef[(string) $this->performerId]['performer']['name']);
        self::assertNull($byRef['not-a-number']['performer']);
        self::assertNull($byRef['Maker One']['performer']);
    }

    public function test_the_feed_lists_new_movies_of_every_follow_kind_and_counts_the_unseen_ones(): void
    {
        $this->putJson("/api/v2/me/follows/performer/{$this->performerId}", [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/follows/series/Saga', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/follows/genre/Drama', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/follows/label/Label%20X', [], $this->csrf)->assertOk();

        $response = $this->getJson('/api/v2/me/feed')->assertOk()->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.seen_until', null)->assertJsonPath('meta.unseen', 2);
        self::assertSame(['BBB-002', 'AAA-001'], array_column($response->json('data'), 'code'));
        self::assertSame([true, true], array_column($response->json('data'), 'unseen'));

        $this->patchJson('/api/v2/me/feed', ['seen_until' => now()->subHours(36)->toIso8601String()], $this->csrf)->assertOk();
        $after = $this->getJson('/api/v2/me/feed?per_page=1')->assertOk()->assertJsonPath('meta.pagination.pages', 2)->assertJsonPath('meta.unseen', 1);
        self::assertTrue($after->json('data.0.unseen'));

        $this->patchJson('/api/v2/me/feed', [], $this->csrf)->assertOk();
        $this->getJson('/api/v2/me/feed')->assertJsonPath('meta.unseen', 0);
    }

    public function test_a_follow_with_quotes_in_its_name_is_matched_exactly(): void
    {
        DB::table('rm_movies')->where('code', 'CCC-003')->update(['maker' => 'Say "Hi" \\ Co']);
        $this->putJson('/api/v2/me/follows/maker/' . rawurlencode('Say "Hi" \\ Co'), [], $this->csrf)->assertOk();

        $this->getJson('/api/v2/me/feed')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.code', 'CCC-003');
    }
}

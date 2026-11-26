<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The /me area of the v2 API on SQLite: scopes, validation, CSRF and everything that needs no PostgreSQL search table. */
final class MeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $csrf = [];

    private function owner(string $role = 'user'): User
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'secret-pass-1']);
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');
        $this->csrf = ['X-CSRF-Token' => (string) $this->app['session.store']->token()];

        return $user;
    }

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        foreach ([
            ['get', '/api/v2/me'], ['patch', '/api/v2/me'], ['patch', '/api/v2/me/preferences'],
            ['get', '/api/v2/me/marks'], ['get', '/api/v2/me/marks/movies'], ['get', '/api/v2/me/marks/favorite/movie/ABC-1'],
            ['put', '/api/v2/me/marks/favorite/movie/ABC-1'], ['delete', '/api/v2/me/marks/favorite/movie/ABC-1'],
            ['get', '/api/v2/me/follows'], ['get', '/api/v2/me/follows/maker/X'], ['put', '/api/v2/me/follows/maker/X'], ['delete', '/api/v2/me/follows/maker/X'],
            ['get', '/api/v2/me/feed'], ['patch', '/api/v2/me/feed'],
            ['get', '/api/v2/me/saved-searches'], ['post', '/api/v2/me/saved-searches'], ['patch', '/api/v2/me/saved-searches/1'], ['delete', '/api/v2/me/saved-searches/1'],
            ['get', '/api/v2/me/history'], ['post', '/api/v2/me/history'], ['delete', '/api/v2/me/history'],
        ] as [$method, $path]) {
            yield "{$method} {$path}" => [$method, $path];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeRoutes(): iterable
    {
        foreach (self::routes() as $name => $route) {
            if ($route[0] !== 'get') {
                yield $name => $route;
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function test_every_route_needs_a_signed_in_user(string $method, string $path): void
    {
        $this->json($method, $path)->assertUnauthorized()->assertJsonPath('code', 'authentication_required');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeRoutes')]
    public function test_unsafe_routes_need_the_csrf_token(string $method, string $path): void
    {
        $this->actingAs(User::factory()->create(), 'web')->json($method, $path)->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');
    }

    public function test_the_profile_shows_the_user_and_the_counters(): void
    {
        $user = $this->owner();
        DB::table('user_marks')->insert([
            ['user_id' => $user->id, 'kind' => 'favourite', 'subject' => 'movie', 'key' => 'A-1', 'created_at' => now()],
            ['user_id' => $user->id, 'kind' => 'follow', 'subject' => 'maker', 'key' => 'M', 'created_at' => now()],
            ['user_id' => $user->id, 'kind' => 'saved_search', 'subject' => 'movies', 'key' => '{"name":"n","query":"q=1"}', 'created_at' => now()],
            ['user_id' => $user->id + 1, 'kind' => 'favourite', 'subject' => 'movie', 'key' => 'A-2', 'created_at' => now()],
        ]);

        $this->getJson('/api/v2/me')->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.counters', ['marks' => 1, 'follows' => 1, 'saved_searches' => 1, 'download_requests' => 0]);
    }

    public function test_the_download_request_counter_reads_the_downloads_table_when_it_exists(): void
    {
        $user = $this->owner();
        DB::statement('CREATE TABLE download_requests (id integer primary key, requester text)');
        DB::table('download_requests')->insert([['requester' => "user:{$user->id}"], ['requester' => 'guest:x']]);

        $this->getJson('/api/v2/me')->assertJsonPath('data.counters.download_requests', 1);
    }

    public function test_a_user_changes_the_name_and_the_password(): void
    {
        $user = $this->owner();

        $this->patchJson('/api/v2/me', ['name' => '  New Name '], $this->csrf)->assertOk()->assertJsonPath('data.name', 'New Name');
        $this->patchJson('/api/v2/me', [], $this->csrf)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->patchJson('/api/v2/me', ['new_password' => 'another-pass-1'], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'current_password', 'code' => 'required']);
        $this->patchJson('/api/v2/me', ['current_password' => 'wrong', 'new_password' => 'another-pass-1'], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'current_password', 'code' => 'incorrect']);
        $this->patchJson('/api/v2/me', ['current_password' => 'secret-pass-1', 'new_password' => 'short'], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'new_password', 'code' => 'min']);
        $this->patchJson('/api/v2/me', ['current_password' => 'secret-pass-1', 'new_password' => 'another-pass-1'], $this->csrf)->assertOk();

        $this->assertTrue(password_verify('another-pass-1', (string) $user->refresh()->password));
    }

    public function test_preferences_are_validated_stored_and_shown_at_sign_in(): void
    {
        $user = $this->owner();

        $this->patchJson('/api/v2/me/preferences', [], $this->csrf)->assertOk()->assertExactJson(['data' => [], 'meta' => []]);
        $this->patchJson('/api/v2/me/preferences', ['language' => 'en', 'privacy' => true, 'page_size' => '24', 'view_mode' => 'table'], $this->csrf)->assertOk()
            ->assertJsonPath('data.language', 'en')->assertJsonPath('data.privacy', true)->assertJsonPath('data.page_size', 24);
        $this->patchJson('/api/v2/me/preferences', ['privacy' => 0, 'density' => 'compact'], $this->csrf)->assertOk()->assertJsonPath('data.privacy', false)->assertJsonPath('data.language', 'en');

        $this->patchJson('/api/v2/me/preferences', ['colour' => 'red', 'language' => 'fr'], $this->csrf)->assertStatus(422)
            ->assertJsonFragment(['field' => 'colour', 'code' => 'unknown'])->assertJsonFragment(['field' => 'language', 'code' => 'in']);

        $this->getJson('/api/v2/sessions/current')->assertJsonPath('data.preferences.density', 'compact');
        $this->assertSame(5, DB::table('ui_preferences')->where('user_id', $user->id)->count());
    }

    public function test_marks_are_idempotent_and_listed_with_the_contract_kinds(): void
    {
        $user = $this->owner();

        $this->putJson('/api/v2/me/marks/favorite/movie/ABC-001', [], $this->csrf)->assertOk()->assertExactJson(['data' => ['kind' => 'favorite', 'target' => 'movie', 'ref' => 'ABC-001', 'on' => true], 'meta' => []]);
        $this->putJson('/api/v2/me/marks/favorite/movie/ABC-001', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/marks/watched/movie/ABC-001', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/marks/hidden/performer/77', [], $this->csrf)->assertOk();
        $this->assertSame(['favourite', 'hidden', 'seen'], DB::table('user_marks')->where('user_id', $user->id)->orderBy('kind')->pluck('kind')->all());

        $this->getJson('/api/v2/me/marks/favorite/movie/ABC-001')->assertOk()->assertJsonPath('data.on', true);
        $this->getJson('/api/v2/me/marks/want/movie/ABC-001')->assertOk()->assertJsonPath('data.on', false);

        $all = $this->getJson('/api/v2/me/marks')->assertOk()->assertJsonPath('meta.pagination.total', 3);
        $this->assertEqualsCanonicalizing(['favorite', 'watched', 'hidden'], array_column($all->json('data'), 'kind'));
        $this->getJson('/api/v2/me/marks?kind=favorite,hidden&target=performer')->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.ref', '77');
        $this->getJson('/api/v2/me/marks?per_page=1&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.pages', 3);
        $this->getJson('/api/v2/me/marks?kind=loved')->assertStatus(422)->assertJsonFragment(['field' => 'kind', 'code' => 'in']);

        $this->deleteJson('/api/v2/me/marks/favorite/movie/ABC-001', [], $this->csrf)->assertNoContent();
        $this->deleteJson('/api/v2/me/marks/favorite/movie/ABC-001', [], $this->csrf)->assertNoContent();
        $this->getJson('/api/v2/me/marks/favorite/movie/ABC-001')->assertJsonPath('data.on', false);
    }

    public function test_an_unknown_mark_kind_or_target_is_not_found(): void
    {
        $this->owner();

        $this->getJson('/api/v2/me/marks/loved/movie/X')->assertNotFound()->assertJsonPath('code', 'mark_kind_not_found');
        $this->putJson('/api/v2/me/marks/favorite/photo/X', [], $this->csrf)->assertNotFound()->assertJsonPath('code', 'mark_target_not_found');
        $this->deleteJson('/api/v2/me/marks/loved/movie/X', [], $this->csrf)->assertNotFound();
    }

    public function test_marked_movies_without_marks_are_an_empty_page(): void
    {
        $this->owner();

        $this->getJson('/api/v2/me/marks/movies')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v2/me/marks/movies?kind=hidden')->assertOk();
        $this->getJson('/api/v2/me/marks/movies?kind=loved')->assertStatus(422);
    }

    public function test_follows_are_idempotent_and_checked(): void
    {
        $this->owner();

        $this->putJson('/api/v2/me/follows/maker/Maker%20One', [], $this->csrf)->assertOk()->assertJsonPath('data', ['kind' => 'maker', 'ref' => 'Maker One', 'following' => true]);
        $this->putJson('/api/v2/me/follows/maker/Maker%20One', [], $this->csrf)->assertOk();
        $this->putJson('/api/v2/me/follows/genre/Drama', [], $this->csrf)->assertOk();
        $this->getJson('/api/v2/me/follows/maker/Maker%20One')->assertOk()->assertJsonPath('data.following', true);
        $this->getJson('/api/v2/me/follows/series/None')->assertOk()->assertJsonPath('data.following', false);

        $list = $this->getJson('/api/v2/me/follows')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['maker', 'genre'], array_column($list->json('data'), 'kind'));
        $this->assertNull($list->json('data.0.performer'));

        $this->deleteJson('/api/v2/me/follows/maker/Maker%20One', [], $this->csrf)->assertNoContent();
        $this->deleteJson('/api/v2/me/follows/maker/Maker%20One', [], $this->csrf)->assertNoContent();
        $this->getJson('/api/v2/me/follows')->assertJsonCount(1, 'data');

        $this->getJson('/api/v2/me/follows/colour/x')->assertNotFound()->assertJsonPath('code', 'follow_kind_not_found');
        $this->putJson('/api/v2/me/follows/colour/x', [], $this->csrf)->assertNotFound();
        $this->deleteJson('/api/v2/me/follows/colour/x', [], $this->csrf)->assertNotFound();
    }

    public function test_the_feed_is_empty_without_follows_and_can_be_marked_seen(): void
    {
        $user = $this->owner();

        $this->getJson('/api/v2/me/feed')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.seen_until', null)->assertJsonPath('meta.unseen', 0);

        $this->patchJson('/api/v2/me/feed', ['seen_until' => '2026-11-25T10:00:00Z'], $this->csrf)->assertOk()->assertJsonPath('data.seen_until', '2026-11-25T10:00:00.000Z');
        $this->getJson('/api/v2/me/feed')->assertJsonPath('meta.seen_until', '2026-11-25T10:00:00.000Z');

        $now = $this->patchJson('/api/v2/me/feed', [], $this->csrf)->assertOk()->json('data.seen_until');
        $this->assertGreaterThan('2026-11-25T10:00:00.000Z', $now);
        $this->patchJson('/api/v2/me/feed', ['seen_until' => 'not a date'], $this->csrf)->assertStatus(422);
        $this->assertSame(1, DB::table('user_marks')->where(['user_id' => $user->id, 'kind' => 'feed_seen'])->count());
    }

    public function test_saved_searches_are_created_renamed_and_deleted(): void
    {
        $user = $this->owner();

        $created = $this->postJson('/api/v2/me/saved-searches', ['name' => ' Amateur ', 'query' => 'q=amateur&released_from=2026'], $this->csrf)->assertCreated()
            ->assertJsonPath('data.name', 'Amateur')->assertJsonPath('data.target', 'movie')->assertJsonPath('data.query', 'q=amateur&released_from=2026');
        $id = $created->json('data.id');
        $this->assertSame("/api/v2/me/saved-searches/{$id}", $created->headers->get('Location'));
        $this->assertSame('movies', DB::table('user_marks')->where('id', $id)->value('subject'));

        $replaced = $this->postJson('/api/v2/me/saved-searches', ['name' => 'Amateur', 'query' => 'q=other'], $this->csrf)->assertCreated()->json('data.id');
        $this->assertNotSame($id, $replaced);
        $this->postJson('/api/v2/me/saved-searches', ['name' => 'Stars', 'query' => 'sort=name', 'target' => 'performer'], $this->csrf)->assertCreated()->assertJsonPath('data.target', 'performer');
        $this->getJson('/api/v2/me/saved-searches')->assertOk()->assertJsonCount(2, 'data');

        $this->patchJson("/api/v2/me/saved-searches/{$replaced}", ['name' => 'Renamed'], $this->csrf)->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.query', 'q=other');
        $this->patchJson("/api/v2/me/saved-searches/{$replaced}", ['query' => 'q=new'], $this->csrf)->assertOk()->assertJsonPath('data.query', 'q=new');
        $this->patchJson("/api/v2/me/saved-searches/{$replaced}", [], $this->csrf)->assertStatus(422);
        $this->patchJson('/api/v2/me/saved-searches/99999', ['name' => 'x'], $this->csrf)->assertNotFound()->assertJsonPath('code', 'saved_search_not_found');

        $other = User::factory()->create();
        $foreign = DB::table('user_marks')->insertGetId(['user_id' => $other->id, 'kind' => 'saved_search', 'subject' => 'movies', 'key' => '{"name":"x","query":"y"}', 'created_at' => now()]);
        $this->patchJson("/api/v2/me/saved-searches/{$foreign}", ['name' => 'mine'], $this->csrf)->assertNotFound();
        $this->deleteJson("/api/v2/me/saved-searches/{$foreign}", [], $this->csrf)->assertNoContent();
        $this->assertSame(1, DB::table('user_marks')->where('id', $foreign)->count());

        $this->deleteJson("/api/v2/me/saved-searches/{$replaced}", [], $this->csrf)->assertNoContent();
        $this->assertSame(1, DB::table('user_marks')->where(['user_id' => $user->id, 'kind' => 'saved_search'])->count());
    }

    public function test_saved_search_input_is_validated(): void
    {
        $this->owner();

        $this->postJson('/api/v2/me/saved-searches', [], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'name', 'code' => 'required'])->assertJsonFragment(['field' => 'query', 'code' => 'required']);
        $this->postJson('/api/v2/me/saved-searches', ['name' => 'a', 'query' => 'b', 'target' => 'photo'], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'target', 'code' => 'in']);
    }

    public function test_history_is_recorded_filtered_and_cleared_per_user(): void
    {
        $user = $this->owner();
        $other = User::factory()->create();
        DB::table('view_history')->insert(['user_id' => $other->id, 'kind' => 'movie', 'ref' => 'ZZZ-9', 'label' => null, 'viewed_at' => now()]);

        $first = $this->postJson('/api/v2/me/history', ['kind' => 'performer', 'ref' => 3846, 'label' => 'Shinoda Yuu'], $this->csrf)->assertCreated()->assertJsonPath('data.ref', '3846')->assertJsonPath('data.label', 'Shinoda Yuu');
        $this->assertSame('/api/v2/me/history', $first->headers->get('Location'));
        $this->postJson('/api/v2/me/history', ['kind' => 'movie', 'ref' => 'SNOS-563'], $this->csrf)->assertCreated()->assertJsonPath('data.label', null);

        $list = $this->getJson('/api/v2/me/history')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->assertSame(['SNOS-563', '3846'], array_column($list->json('data'), 'ref'));
        $this->getJson('/api/v2/me/history?kind=performer')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v2/me/history?kind=other')->assertStatus(422);
        $this->getJson('/api/v2/me/history?per_page=101')->assertStatus(422);

        $this->postJson('/api/v2/me/history', ['kind' => 'other', 'ref' => 'x'], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'kind', 'code' => 'in']);
        $this->postJson('/api/v2/me/history', ['kind' => 'movie'], $this->csrf)->assertStatus(422)->assertJsonFragment(['field' => 'ref', 'code' => 'required']);

        $this->deleteJson('/api/v2/me/history', [], $this->csrf)->assertNoContent();
        $this->assertSame(0, DB::table('view_history')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('view_history')->where('user_id', $other->id)->count());
    }

    public function test_an_administrator_may_use_the_owner_routes(): void
    {
        $this->owner('super_administrator');

        $this->getJson('/api/v2/me')->assertOk();
    }
}

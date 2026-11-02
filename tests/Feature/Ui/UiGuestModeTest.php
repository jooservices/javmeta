<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Http\Controllers\Ui\UiApiController;
use App\Http\Middleware\GuestModeRoutePolicy;
use App\Http\Middleware\EnsureUiOwnerOrGuest;
use App\Models\CrawlEvent;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\Performer;
use App\Models\Source;
use App\Models\User;
use App\Services\Ui\UiLoginStateRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class UiGuestModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'javmeta.ui_login_required' => false,
            'javmeta.ui_guest_rate_limit_per_minute' => 20,
            'javmeta_domains.command_center_host' => 'ops.internal.test',
        ]);
        Cache::forget('javmeta:ui-login:recorded-state');
        Cache::forget('javmeta:ui-login:recording');
        Cache::forget('javmeta:status:summary');
        Cache::forget('javmeta:status:sources');
    }

    protected function tearDown(): void
    {
        Cache::forget('javmeta:ui-login:recorded-state');
        Cache::forget('javmeta:ui-login:recording');

        parent::tearDown();
    }

    public function test_guest_shell_is_noindex_and_does_not_start_a_session_or_include_private_metadata(): void
    {
        $response = $this->get('/app')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('name="robots" content="noindex"', false)
            ->assertSee('name="ui-guest-mode" content="true"', false)
            ->assertSee('Đăng nhập để dùng các tính năng cá nhân và quản trị', false)
            ->assertDontSee('name="csrf-token"', false)
            ->assertDontSee('name="command-center-url"', false);

        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('/ui/js/app.js', (string) $response->getContent());
        self::assertFileExists(public_path('ui/app.css'));
    }

    public function test_guest_does_not_renew_a_stale_session_cookie(): void
    {
        $response = $this->withCookie((string) config('session.cookie'), 'stale-session-id')
            ->get('/app')
            ->assertOk();

        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_guest_can_read_only_catalog_search_and_public_safe_crawl_status(): void
    {
        Http::fake();
        Source::factory()->create([
            'slug' => 'public-source',
            'name' => 'Public source',
            'last_error' => 'http://10.0.0.7/private /srv/javmeta/storage/app/private/video.mp4',
        ]);
        $performer = Performer::factory()->create([
            'source_slug' => 'public-source',
            'external_id' => 'guest-performer-1',
            'name_romaji' => 'Public performer',
            'profile_url' => null,
            'image_url' => null,
        ]);
        $relatedMovie = Movie::factory()->create([
            'display_code' => 'GST-091',
            'maker' => null,
            'series' => null,
        ]);
        MovieCode::factory()->create([
            'movie_id' => $relatedMovie->id,
            'code' => 'GST-091',
            'code_normalized' => 'GST091',
            'kind' => MovieCode::KIND_DVD,
        ]);

        foreach ([
            '/ui-api/movies',
            '/ui-api/performers',
            '/ui-api/performers/' . $performer->uuid,
            '/ui-api/search?q=public+movie',
            '/ui-api/suggest?q=public',
            '/ui-api/movies/facets',
            '/ui-api/performers/facets',
            '/ui-api/meta/genres',
            '/ui-api/meta/catalog/makers',
            '/ui-api/movies/calendar?month=2026-10',
            '/ui-api/movies/GST-091/related',
            '/ui-api/status/summary',
        ] as $path) {
            $this->getJson($path)->assertOk();
        }

        $sources = $this->getJson('/ui-api/status/sources')->assertOk();
        $sources->assertJsonPath('data.0.slug', 'public-source');
        $this->assertArrayNotHasKey('last_error', $sources->json('data.0'));
        $this->assertArrayNotHasKey('last_error_at', $sources->json('data.0'));
        $this->assertArrayHasKey('has_recent_failure', $sources->json('data.0'));
        $this->assertStringNotContainsString('10.0.0.7', (string) $sources->getContent());
        $this->assertStringNotContainsString('/srv/javmeta', (string) $sources->getContent());
        $this->getJson('/ui-api/movies?on_disk=1')->assertForbidden();
        $this->getJson('/ui-api/scenes/search?q=private')
            ->assertForbidden()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $bridge = $this->getJson('/ui-api/movies')->assertOk();
        $bridge->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('no-store', (string) $bridge->headers->get('Cache-Control'));
        self::assertFalse($bridge->headers->has('Set-Cookie'));
    }

    public function test_guest_movie_details_do_not_expose_private_paths_or_signed_media_links(): void
    {
        $movie = Movie::factory()->create([
            'display_code' => 'GUEST-001',
            'attrs' => [
                'disk_path' => '/srv/javmeta/private/video.mp4',
                'crawler_endpoint' => 'http://crawler:8080/internal/status',
                'api_key' => 'guest-api-key-fixture',
                'private_key' => 'private-key-fixture',
                'internal_host' => 'Worker is at 10.0.0.7.',
                'stack_trace' => 'RuntimeException at /srv/javmeta/private/source.php:42',
            ],
        ]);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'GUEST-001',
            'code_normalized' => 'GUEST001',
            'kind' => MovieCode::KIND_DVD,
        ]);
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_HLS,
            'url' => 'https://cdn.example.com/media/video.m3u8?signature=private-signature',
        ]);
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_GALLERY,
            'url' => 'https://cdn.example.com/media/preview.jpg?expires=private-expiry',
        ]);

        $response = $this->getJson('/ui-api/movies/GUEST-001')->assertOk();

        $response->assertJsonMissingPath('data.magnets');
        $response->assertJsonMissingPath('data.hls_stream_urls');
        $response->assertJsonMissingPath('data.gallery');
        $response->assertJsonMissingPath('data.photos');
        $this->assertStringNotContainsString('/srv/javmeta', (string) $response->getContent());
        $this->assertStringNotContainsString('crawler:8080', (string) $response->getContent());
        $this->assertStringNotContainsString('guest-api-key-fixture', (string) $response->getContent());
        $this->assertStringNotContainsString('private-key-fixture', (string) $response->getContent());
        $this->assertStringNotContainsString('private-signature', (string) $response->getContent());
        $this->assertStringNotContainsString('private-expiry', (string) $response->getContent());
        $this->assertStringNotContainsString('10.0.0.7', (string) $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', (string) $response->getContent());
    }

    public function test_guest_catalog_does_not_return_the_owners_personal_marks_or_searches(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $createdAt = now();
        DB::table('user_marks')->insert([
            ['user_id' => $owner->id, 'kind' => 'favourite', 'subject' => 'movie', 'key' => 'owner-private-favourite-canary', 'created_at' => $createdAt],
            ['user_id' => $owner->id, 'kind' => 'watch_later', 'subject' => 'movie', 'key' => 'owner-private-watch-canary', 'created_at' => $createdAt],
            ['user_id' => $owner->id, 'kind' => 'saved_search', 'subject' => 'movies', 'key' => 'owner-private-search-canary', 'created_at' => $createdAt],
        ]);

        foreach (['/ui-api/movies', '/ui-api/performers', '/ui-api/search?q=public+catalog'] as $path) {
            $response = $this->getJson($path)->assertOk();

            foreach ([
                'owner-private-favourite-canary',
                'owner-private-watch-canary',
                'owner-private-search-canary',
                'user_marks',
            ] as $privateValue) {
                $this->assertStringNotContainsString($privateValue, (string) $response->getContent());
            }
        }
    }

    public function test_guest_cannot_access_privileged_routes_or_file_endpoints(): void
    {
        $responses = [
            '/ui-admin/keys' => $this->getJson('/ui-admin/keys'),
            'ui-admin key write' => $this->postJson('/ui-admin/keys', ['label' => 'guest']),
            '/ui-admin/report/quality' => $this->getJson('/ui-admin/report/quality'),
            '/ui-admin/settings' => $this->getJson('/ui-admin/settings'),
            'ui-admin settings write' => $this->putJson('/ui-admin/settings', ['settings' => []]),
            'API source cookie write' => $this->putJson('/api/v1/admin/sources/private-source/login-cookies', ['cookies' => 'private']),
            '/ui-me/marks' => $this->getJson('/ui-me/marks'),
            'ui-me mark write' => $this->postJson('/ui-me/marks', ['kind' => 'favourite', 'subject' => 'movie', 'key' => 'GUEST-001']),
            '/ui-me/feed' => $this->getJson('/ui-me/feed'),
            'ui-me feed write' => $this->postJson('/ui-me/feed/seen'),
            '/ui-me/downloads' => $this->getJson('/ui-me/downloads'),
            'ui-me download write' => $this->postJson('/ui-me/downloads', ['code' => 'GUEST-001']),
            '/ui-me/library' => $this->getJson('/ui-me/library'),
            '/ui-me/media' => $this->getJson('/ui-me/media'),
            'media job write' => $this->postJson('/ui-me/media/capture', ['url' => 'https://media.example/video']),
            '/ui-me/player' => $this->getJson('/ui-me/player/GUEST-001'),
            'signed download' => $this->getJson('/files/download'),
            'signed file read' => $this->getJson('/storage/private.txt'),
            'signed file write' => $this->putJson('/storage/private.txt', ['contents' => 'guest write']),
            '/ops' => $this->getJson('/ops'),
            '/ops/data' => $this->getJson('/ops/data'),
            '/horizon' => $this->getJson('/horizon/api/stats'),
            'API media job' => $this->getJson('/api/v1/media/jobs/next'),
            'API status remains API-key protected' => $this->getJson('/api/v1/status/summary'),
        ];

        foreach ($responses as $path => $response) {
            $this->assertContains($response->getStatusCode(), [401, 403], 'Expected access denial for ' . $path . '.');
        }

        $responses['/ui-admin/keys']->assertJsonStructure(['message']);
        $responses['/ui-me/marks']->assertJsonStructure(['message']);
        $this->postJson('/ui-api/movies', ['query' => 'guest'])->assertForbidden()->assertJsonStructure(['message']);
        $this->getJson('/api/v1/movies')->assertUnauthorized();
    }

    public function test_a_real_owner_keeps_privileged_access_when_guest_mode_is_enabled(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);

        $this->actingAs($owner)->get('/app')->assertOk()->assertSee('name="ui-guest-mode" content="false"', false);
        $this->actingAs($owner)->getJson('/ui-admin/keys')->assertOk();
        $this->actingAs($owner)->getJson('/ui-me/marks')->assertOk();
    }

    public function test_login_required_mode_is_the_default_for_the_shell_and_privileged_pages(): void
    {
        config(['javmeta.ui_login_required' => true]);

        $this->get('/app')->assertRedirect(route('login'));
        $this->getJson('/ui-api/movies')->assertUnauthorized();
        $this->getJson('/ui-me/marks')->assertUnauthorized();
        $this->getJson('/ui-admin/keys')->assertUnauthorized();
        $this->getJson('/ops')->assertUnauthorized();
    }

    public function test_owner_can_open_a_signed_private_file_in_guest_mode(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private.txt', 'private owner file');
        $url = URL::temporarySignedRoute('storage.local', now()->addMinutes(5), ['path' => 'private.txt'], absolute: false);
        $owner = User::factory()->create(['role' => 'super_administrator']);

        $this->actingAs($owner)->get($url)->assertOk();
    }

    public function test_turning_login_back_on_immediately_protects_the_shell_and_bridge(): void
    {
        $this->getJson('/ui-api/movies')->assertOk();

        config(['javmeta.ui_login_required' => true]);

        $this->get('/app')->assertRedirect(route('login'));
        $this->getJson('/ui-api/movies')->assertUnauthorized();
    }

    public function test_guest_bridge_rate_limit_uses_the_configured_per_ip_setting(): void
    {
        config(['javmeta.ui_guest_rate_limit_per_minute' => 2]);
        $ip = '198.51.100.42';
        RateLimiter::clear('ui-guest:' . hash('sha256', $ip));

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/ui-api/movies')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/ui-api/movies')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/ui-api/movies')
            ->assertTooManyRequests()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_login_state_is_recorded_once_until_the_setting_changes(): void
    {
        app(UiLoginStateRecorder::class)->record();
        app(UiLoginStateRecorder::class)->record();

        $this->assertSame(1, CrawlEvent::query()->where('kind', CrawlEvent::KIND_UI_LOGIN_STATE_CHANGED)->count());

        config(['javmeta.ui_login_required' => true]);
        app(UiLoginStateRecorder::class)->record();

        $this->assertSame(2, CrawlEvent::query()->where('kind', CrawlEvent::KIND_UI_LOGIN_STATE_CHANGED)->count());
    }

    public function test_every_route_matches_the_guest_matrix_and_private_routes_reject_anonymous_requests(): void
    {
        config([
            'javmeta.ui_login_required' => false,
            'javmeta_auth.owner_admin_token' => 'owner-token-canary',
            'javmeta_auth.mcp_api_key' => 'mcp-key-canary',
            'laravel-controller.status.include_environment' => true,
            'laravel-controller.status.include_version' => true,
            'laravel-controller.status.include_maintenance' => true,
        ]);

        $matrix = $this->documentedRouteMatrix();
        $actualRoutes = [];
        foreach (Route::getRoutes() as $route) {
            $identifier = $this->routeIdentifier($route);
            $actualRoutes[] = $identifier;
            $this->assertArrayHasKey($identifier, $matrix, 'Route is missing from the guest matrix: ' . $identifier);

            $allowed = in_array($identifier, GuestModeRoutePolicy::ALLOWED_ROUTES, true);
            $this->assertSame($allowed, $matrix[$identifier]['guest_allowed'], 'Guest matrix classification differs from code: ' . $identifier);

            if ($allowed) {
                $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $route->methods(), 'Guest routes must be GET/HEAD only: ' . $identifier);
                $response = $this->call('GET', $this->routeProbePath($route));
                $this->assertContains($response->getStatusCode(), [200, 302, 503], 'Guest route did not respond safely: ' . $identifier);
                $this->assertGuestResponseContainsNoKnownSecrets($response->getContent());

                if (in_array($route->uri(), ['app', 'ui-api/{path}'], true)) {
                    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
                    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                }

                continue;
            }

            foreach ($route->methods() as $method) {
                $server = ['HTTP_ACCEPT' => 'application/json'];
                if ($route->uri() === 'login' && $method === 'POST') {
                    $server = ['HTTP_REFERER' => '/login'];
                }

                $response = $this->call($method, $this->routeProbePath($route), [], [], [], $server);
                if ($route->uri() === 'login' && $method === 'POST') {
                    self::assertSame(302, $response->getStatusCode());
                    self::assertSame('/login', parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

                    continue;
                }

                $this->assertContains(
                    $response->getStatusCode(),
                    [401, 403],
                    'Expected anonymous access to be denied for ' . $identifier . ' using ' . $method . '.',
                );
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($matrix), $actualRoutes, 'The route matrix must classify every registered route exactly once.');
        $documentedGuestRoutes = array_keys(array_filter($matrix, static fn(array $route): bool => $route['guest_allowed']));
        $this->assertEqualsCanonicalizing(GuestModeRoutePolicy::ALLOWED_ROUTES, $documentedGuestRoutes);

        $guestRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array(EnsureUiOwnerOrGuest::class, $route->gatherMiddleware(), true)) {
                $guestRoutes[] = $this->routeIdentifier($route);
            }
        }
        $this->assertEqualsCanonicalizing(['GET|HEAD app', 'GET|HEAD ui-api/{path}', 'DELETE|PATCH|POST|PUT ui-api/{path}'], $guestRoutes);

        foreach (['movies', 'movies/AAA-001', 'performers', 'search', 'suggest', 'meta/catalog/genres', 'movies/calendar', 'status/summary'] as $path) {
            $this->assertTrue(UiApiController::guestPathAllowed($path), 'Public bridge path is missing: ' . $path);
        }
        foreach (['scenes/search', 'status', 'admin/keys', 'movies:bulk', 'search/parse', 'media/jobs/next'] as $path) {
            $this->assertFalse(UiApiController::guestPathAllowed($path), 'Private bridge path became public: ' . $path);
        }
    }

    public function test_guest_errors_do_not_return_exception_details_and_remain_noindex(): void
    {
        config(['app.debug' => true]);
        $request = Request::create('/ui-api/movies', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = app(EnsureUiOwnerOrGuest::class)->handle($request, static function (Request $request): Response {
            throw new RuntimeException('Synthetic failure at /srv/javmeta/private/source.php:42');
        });

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('Synthetic failure', (string) $response->getContent());
        self::assertStringNotContainsString('/srv/javmeta', (string) $response->getContent());
        self::assertStringNotContainsString('RuntimeException', (string) $response->getContent());
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_public_package_status_keeps_its_full_payload_when_guest_mode_is_off(): void
    {
        config([
            'javmeta.ui_login_required' => true,
            'laravel-controller.status.include_environment' => true,
            'laravel-controller.status.include_version' => true,
            'laravel-controller.status.include_maintenance' => true,
        ]);

        $this->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('data.environment', 'testing')
            ->assertJsonPath('data.version', app()->version())
            ->assertJsonPath('data.maintenance', false);
    }

    /** @return array<string, array{guest_allowed: bool}> */
    private function documentedRouteMatrix(): array
    {
        $document = file_get_contents(base_path('docs/03-operations/guest-mode.md'));
        self::assertIsString($document);
        preg_match_all('/^\|\s*`([^`]+)`\s*\|\s*`([^`]+)`\s*\|\s*(Guest allowed|Login required)\s*\|[^|]+\|$/m', $document, $matches, PREG_SET_ORDER);

        $routes = [];
        foreach ($matches as $match) {
            $methods = array_map('trim', explode(',', $match[1]));
            sort($methods);
            $identifier = implode('|', $methods) . ' ' . $match[2];
            $this->assertArrayNotHasKey($identifier, $routes, 'Route is duplicated in the guest matrix: ' . $identifier);
            $routes[$identifier] = ['guest_allowed' => $match[3] === 'Guest allowed'];
        }

        return $routes;
    }

    private function routeIdentifier(RouteDefinition $route): string
    {
        $methods = $route->methods();
        sort($methods);

        return implode('|', $methods) . ' ' . $route->uri();
    }

    private function routeProbePath(RouteDefinition $route): string
    {
        if ($route->uri() === 'ui-api/{path}') {
            return '/ui-api/movies';
        }

        $uri = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?:\?)?\}/',
            static fn(array $match): string => match ($match[1]) {
                'hash' => 'guesthash123',
                'kind' => 'genres',
                'path' => 'classification-check',
                'slug' => 'guest-source',
                'tag' => 'guest-tag',
                'type' => 'quality',
                'view' => 'index',
                'code' => 'GST-001',
                default => '1',
            },
            $route->uri(),
        ) ?? $route->uri();

        return '/' . ltrim($uri, '/');
    }

    private function assertGuestResponseContainsNoKnownSecrets(?string $content): void
    {
        $content ??= '';
        foreach ([
            'owner-token-canary',
            'mcp-key-canary',
            'guest-api-key-fixture',
            'private-key-fixture',
            'private-signature',
            'private-expiry',
            '10.0.0.7',
            '/srv/javmeta',
            '/home/joos/',
            'APP_KEY=',
            'DB_PASSWORD=',
            'testing',
            '/files/download',
            '/storage/',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }

        $this->assertDoesNotMatchRegularExpression('/(?:Stack trace:|#0\s+|Illuminate\\\\.*Exception)/i', $content);
    }
}

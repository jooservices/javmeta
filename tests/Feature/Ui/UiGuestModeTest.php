<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Http\Controllers\Ui\UiApiController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureSuperAdministrator;
use App\Http\Middleware\EnsureUiOwnerOrGuest;
use App\Http\Middleware\OwnerAdminGuard;
use App\Models\CrawlEvent;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\Performer;
use App\Models\Source;
use App\Models\User;
use App\Services\Ui\UiLoginStateRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        $this->getJson('/ui-api/scenes/search?q=private')->assertForbidden();
        $bridge = $this->getJson('/ui-api/movies')->assertOk();
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
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/ui-api/movies')->assertTooManyRequests();
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

    public function test_every_registered_route_is_classified_and_guest_bridge_paths_default_to_denied(): void
    {
        $this->getJson('/storage/classification-check')->assertUnauthorized();
        $this->putJson('/storage/classification-check', ['contents' => 'guest write'])->assertUnauthorized();

        $publicRoutes = [
            'GET /',
            'GET login',
            'POST login',
            'GET api/health',
            'GET api/health/live',
            'GET api/openapi.json',
            // Package liveness endpoint; remains intentionally public and unchanged.
            'GET api/v1/status',
            'GET up',
        ];
        $protectionMiddleware = [
            'auth', 'horizon', 'viewHorizon', EnsureSuperAdministrator::class,
            AuthenticateApiKey::class, OwnerAdminGuard::class,
        ];
        $guestRoutes = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array(EnsureUiOwnerOrGuest::class, $middleware, true)) {
                $guestRoutes[] = $route->uri();
                $this->assertContains($route->uri(), ['app', 'ui-api/{path}']);
                if ($route->uri() === 'app') {
                    $this->assertSame(['GET', 'HEAD'], $route->methods());
                } else {
                    $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $route->methods());
                }

                continue;
            }

            $this->assertNotContains(EnsureUiOwnerOrGuest::class, $middleware);
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            sort($methods);
            if (in_array(implode('|', $methods) . ' ' . $route->uri(), $publicRoutes, true)) {
                continue;
            }

            $this->assertNotEmpty(
                array_intersect($protectionMiddleware, $middleware),
                'Unclassified route could bypass guest mode: ' . implode('|', $route->methods()) . ' ' . $route->uri(),
            );
        }

        $this->assertEqualsCanonicalizing(['app', 'ui-api/{path}'], $guestRoutes);
        foreach (['movies', 'movies/AAA-001', 'performers', 'search', 'suggest', 'meta/catalog/genres', 'movies/calendar', 'status/summary'] as $path) {
            $this->assertTrue(UiApiController::guestPathAllowed($path), 'Public bridge path is missing: ' . $path);
        }
        foreach (['scenes/search', 'status', 'admin/keys', 'movies:bulk', 'search/parse', 'media/jobs/next'] as $path) {
            $this->assertFalse(UiApiController::guestPathAllowed($path), 'Private bridge path became public: ' . $path);
        }
    }
}

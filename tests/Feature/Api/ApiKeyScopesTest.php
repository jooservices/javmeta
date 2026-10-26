<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\ForbiddenScopeException;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use App\Models\ApiKey;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiKeyScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scoped_key_reaches_only_its_scopes(): void
    {
        $key = app(ApiKeyService::class)->create('scoped', 60, ['catalog'])->plaintext;
        $headers = ['X-API-Key' => $key];

        $this->getJson('/api/v1/meta/catalog/makers', $headers)->assertOk();
        $this->getJson('/api/v1/suggest?q=abc', $headers)->assertOk();
        $this->getJson('/api/v1/performers', $headers)->assertStatus(403)->assertJsonPath('type', 'forbidden_scope');
        $this->getJson('/api/v1/movies/calendar', $headers)->assertStatus(403);
        $this->getJson('/api/v1/status/summary', $headers)->assertStatus(403);
    }

    public function test_a_key_without_scopes_keeps_full_access(): void
    {
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('all')->plaintext];

        $this->getJson('/api/v1/performers', $headers)->assertOk();
        $this->getJson('/api/v1/movies/calendar', $headers)->assertOk();
        $this->assertNull(ApiKey::query()->where('label', 'all')->first()->scopes);
    }

    public function test_encoded_routes_cannot_bypass_scopes(): void
    {
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('status', 60, ['status'])->plaintext];
        foreach (['/api/v1/%6dovies', '/api/%761/movies', '/api/v1/performers/', '/api/v1/movies/ABC%2D001'] as $path) {
            $this->getJson($path, $headers)->assertForbidden()->assertJsonPath('type', 'forbidden_scope');
        }
        foreach (['/api/v1/movies:bulk', '/api/v1/movies%3Abulk'] as $path) {
            $this->postJson($path, ['codes' => ['ABC-001']], $headers)->assertForbidden();
        }
        $this->getJson('/api/v1/auth/verify', $headers)->assertOk();
    }

    public function test_undeclared_route_fails_closed_even_for_an_unrestricted_key(): void
    {
        $request = Request::create('/api/v1/unknown');
        $route = new Route('GET', 'api/v1/unknown', static fn() => response()->json([]));
        $request->setRouteResolver(static fn() => $route);
        $request->headers->set('X-API-Key', app(ApiKeyService::class)->create('all')->plaintext);
        $this->expectException(ForbiddenScopeException::class);
        app(AuthenticateApiKey::class)->handle($request, static fn() => response()->json([]));
    }

    public function test_every_key_protected_route_has_an_explicit_scope_policy(): void
    {
        $key = app(ApiKeyService::class)->create('all')->plaintext;
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array(AuthenticateApiKey::class, $route->gatherMiddleware(), true)) {
                continue;
            }
            $request = Request::create('/' . $route->uri());
            $request->setRouteResolver(static fn() => $route);
            $request->headers->set('X-API-Key', $key);
            $response = app(AuthenticateApiKey::class)->handle($request, static fn() => response()->json(['allowed' => true]));
            $this->assertSame(200, $response->getStatusCode(), $route->uri());
        }
    }
}

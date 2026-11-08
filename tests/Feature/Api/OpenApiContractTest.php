<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OpenApiContractTest extends TestCase
{
    public function test_the_contract_is_public_and_valid(): void
    {
        $spec = $this->getJson('/api/openapi.json')->assertOk()->json();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('ApiKey', $spec['components']['securitySchemes']);
    }

    public function test_every_api_route_is_in_the_contract_and_every_contract_path_is_a_route(): void
    {
        $spec = json_decode((string) file_get_contents(resource_path('openapi/javmeta.json')), true, 512, JSON_THROW_ON_ERROR);
        $documented = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $documented[] = strtoupper($method) . ' /' . ltrim($path, '/');
            }
        }

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/' . $route->uri();
            if (! str_starts_with($uri, '/api/') || $uri === '/api/openapi.json') {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = $method . ' ' . $uri;
                }
            }
        }

        sort($documented);
        sort($routes);
        $this->assertSame([], array_values(array_diff($routes, $documented)), 'API routes missing from resources/openapi/javmeta.json');
        $this->assertSame([], array_values(array_diff($documented, $routes)), 'Contract paths that are not routes');
    }
}

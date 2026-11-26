<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** Every operation of docs/api/openapi.yaml is routed, and every v2 route is in the contract. */
final class ContractRoutesTest extends TestCase
{
    /** The path with every parameter name reduced to {}, so a different parameter name is still the same operation. */
    private function shape(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }

    /** @return list<string> */
    private function contract(): array
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $operations = [];

        foreach ($spec['paths'] as $path => $item) {
            foreach (array_intersect(array_keys($item), ['get', 'post', 'put', 'patch', 'delete']) as $method) {
                $operations[] = strtoupper($method) . ' ' . $this->shape('api/v2/' . ltrim((string) $path, '/'));
            }
        }

        sort($operations);

        return $operations;
    }

    /** @return list<string> */
    private function routed(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v2/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = $method . ' ' . $this->shape($route->uri());
                }
            }
        }

        sort($routes);

        return $routes;
    }

    public function test_every_contract_operation_is_routed(): void
    {
        $missing = array_values(array_diff($this->contract(), $this->routed()));

        self::assertSame([], $missing, 'operations in the contract without a route');
    }

    public function test_no_v2_route_is_outside_the_contract(): void
    {
        $extra = array_values(array_diff($this->routed(), $this->contract(), ['GET api/v2/openapi.json']));

        self::assertSame([], $extra, 'v2 routes that are not in the contract');
    }
}

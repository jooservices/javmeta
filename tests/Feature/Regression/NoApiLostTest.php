<?php

declare(strict_types=1);

namespace Tests\Feature\Regression;

use App\Services\Mcp\McpToolService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The v2 UI API is added next to the existing surface; nothing that existed before may disappear or change.
 * The fixtures were captured from the code before v2 (routes outside /api/v2 and the 13 MCP tools).
 */
final class NoApiLostTest extends TestCase
{
    public function test_every_route_that_existed_before_v2_still_exists(): void
    {
        $now = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $now[] = $method . ' ' . $route->uri();
            }
        }

        $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/regression/routes.json')), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], array_values(array_diff($expected, $now)), 'a route that existed before v2 is gone');
    }

    public function test_the_mcp_tools_and_their_input_schemas_are_unchanged(): void
    {
        $now = array_map(
            static fn(array $tool): array => ['name' => $tool['name'], 'schema' => md5(json_encode($tool['inputSchema'] ?? [], JSON_THROW_ON_ERROR))],
            app(McpToolService::class)->definitions(),
        );
        usort($now, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/regression/mcp-tools.json')), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expected, $now);
    }
}

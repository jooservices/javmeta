<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\McpHttpController;
use App\Services\Mcp\McpRequestHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

final class McpHttpControllerTest extends TestCase
{
    public function test_it_returns_a_parse_error_for_malformed_json(): void
    {
        $response = (new McpHttpController())(
            Request::create('/api/v1/mcp', 'POST', content: '{'),
            app(McpRequestHandler::class),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32700, $response->getData(true)['error']['code']);
    }

    public function test_it_returns_invalid_request_for_json_that_is_not_an_object(): void
    {
        $response = (new McpHttpController())(
            Request::create('/api/v1/mcp', 'POST', content: 'null'),
            app(McpRequestHandler::class),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32600, $response->getData(true)['error']['code']);
    }

    public function test_it_returns_accepted_with_an_empty_object_for_initialized_notifications(): void
    {
        $response = (new McpHttpController())(
            Request::create('/api/v1/mcp', 'POST', content: '{"jsonrpc":"2.0","method":"notifications/initialized"}'),
            app(McpRequestHandler::class),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([], get_object_vars($response->getData()));
    }

    public function test_it_returns_sse_headers_for_get_requests(): void
    {
        $response = (new McpHttpController())(
            Request::create('/api/v1/mcp', 'GET'),
            app(McpRequestHandler::class),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }
}

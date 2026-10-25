<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mcp;

use App\Services\Auth\ApiKeyService;
use App\Services\Mcp\McpRequestHandler;
use App\Services\Mcp\McpToolService;
use Tests\TestCase;

final class McpRequestHandlerTest extends TestCase
{
    public function test_it_returns_invalid_request_when_method_is_not_a_string(): void
    {
        $response = app(McpRequestHandler::class)->handle(['id' => 7, 'method' => ['initialize']]);

        self::assertSame(-32600, $response['error']['code']);
        self::assertSame(7, $response['id']);
    }

    public function test_it_returns_method_not_found_when_method_is_unknown(): void
    {
        $response = app(McpRequestHandler::class)->handle(['id' => 8, 'method' => 'not/a/method']);

        self::assertSame(-32601, $response['error']['code']);
        self::assertSame('Method not found: not/a/method', $response['error']['message']);
    }

    public function test_it_returns_the_supported_protocol_when_initialize_has_an_id(): void
    {
        $response = app(McpRequestHandler::class)->handle(['id' => 9, 'method' => 'initialize']);

        self::assertSame('2024-11-05', $response['result']['protocolVersion']);
        self::assertSame('javmeta', $response['result']['serverInfo']['name']);
    }

    public function test_it_does_not_respond_when_initialized_notification_has_no_id(): void
    {
        $handler = app(McpRequestHandler::class);
        $response = $handler->handle(['method' => 'notifications/initialized']);
        $responseWithId = $handler->handle(['id' => 12, 'method' => 'notifications/initialized']);

        self::assertNull($response);
        self::assertNull($responseWithId);
    }

    public function test_it_lists_available_tools_when_tools_list_is_requested(): void
    {
        $response = app(McpRequestHandler::class)->handle(['id' => 10, 'method' => 'tools/list']);

        self::assertCount(13, $response['result']['tools']);
        self::assertSame('lookup_movies', $response['result']['tools'][0]['name']);
    }

    public function test_it_marks_tool_calls_unauthorized_when_no_active_key_is_configured(): void
    {
        config()->set('javmeta_auth.mcp_api_key', '');

        $response = app(McpRequestHandler::class)->handle([
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'lookup_movies'],
        ]);

        self::assertTrue($response['result']['isError']);
        self::assertSame('{"error":"unauthorized"}', $response['result']['content'][0]['text']);
    }

    public function test_it_wraps_invalid_tool_arguments_as_a_clear_tool_error(): void
    {
        config()->set('javmeta_auth.mcp_api_key', '');

        $response = (new McpRequestHandler(app(McpToolService::class), app(ApiKeyService::class)))->handle([
            'id' => 12,
            'method' => 'tools/call',
            'params' => ['name' => 'get_movie', 'arguments' => []],
        ], true);

        self::assertTrue($response['result']['isError']);
        self::assertSame([
            'error' => 'invalid_filter',
            'message' => 'code is required.',
        ], json_decode($response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR));
    }
}

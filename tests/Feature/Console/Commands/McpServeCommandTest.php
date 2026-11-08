<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Mcp\CaptureStdout;
use Tests\Support\Mcp\ScriptedStdin;
use Tests\TestCase;

/** mcp:serve reads JSON-RPC lines from stdin and writes responses to STDOUT; both are scripted here. */
final class McpServeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CaptureStdout::stop();
        ScriptedStdin::restore();

        parent::tearDown();
    }

    public function test_it_refuses_to_serve_without_an_authorised_mcp_key(): void
    {
        config(['javmeta_auth.mcp_api_key' => '']);

        $this->artisan('mcp:serve')
            ->expectsOutputToContain('MCP auth failed')
            ->assertFailed();
    }

    public function test_it_refuses_a_key_that_is_not_allowed_the_mcp_scope(): void
    {
        $key = app(ApiKeyService::class)->create('no-mcp', 60, ['catalog']);
        config(['javmeta_auth.mcp_api_key' => $key->plaintext]);

        $this->artisan('mcp:serve')->assertFailed();
    }

    public function test_it_answers_each_json_rpc_line_until_stdin_ends(): void
    {
        $key = app(ApiKeyService::class)->create('mcp', 60, ['mcp']);
        config(['javmeta_auth.mcp_api_key' => $key->plaintext]);
        $lines = [
            '',
            '   ',
            '{not json',
            '123',
            '{"jsonrpc":"2.0","id":1,"method":"initialize"}',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            '{"jsonrpc":"2.0","id":2,"method":"ping"}',
            '{"jsonrpc":"2.0","id":3,"method":"unknown/method"}',
        ];
        ScriptedStdin::install(implode("\n", $lines) . "\n");
        CaptureStdout::start();

        $exitCode = Artisan::call('mcp:serve');

        $written = CaptureStdout::stop();
        ScriptedStdin::restore();
        self::assertSame(0, $exitCode);

        $responses = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $written), static fn(string $line): bool => $line !== '')),
        );
        self::assertCount(5, $responses, 'blank lines and notifications produce no response');
        self::assertSame(-32700, $responses[0]['error']['code']);
        self::assertSame('Parse error', $responses[0]['error']['message']);
        self::assertNull($responses[0]['id']);
        self::assertSame(-32600, $responses[1]['error']['code']);
        self::assertSame('Invalid Request', $responses[1]['error']['message']);
        self::assertSame(1, $responses[2]['id']);
        self::assertSame('2024-11-05', $responses[2]['result']['protocolVersion']);
        self::assertSame(2, $responses[3]['id']);
        self::assertSame(3, $responses[4]['id']);
        self::assertSame(-32601, $responses[4]['error']['code']);
        self::assertSame('Method not found: unknown/method', $responses[4]['error']['message']);
    }
}

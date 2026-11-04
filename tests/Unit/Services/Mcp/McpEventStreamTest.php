<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mcp;

use App\Services\Mcp\McpEventStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpEventStream::class)]
final class McpEventStreamTest extends TestCase
{
    public function test_it_announces_the_endpoint_then_sends_one_keep_alive_per_beat(): void
    {
        $waits = [];
        $stream = new McpEventStream('/api/v1/mcp', 2, 7, static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        });

        $output = $this->capture($stream);

        self::assertStringStartsWith("event: endpoint\ndata: {\"uri\":\"\\/api\\/v1\\/mcp\"}\n\n", $output);
        self::assertSame(2, substr_count($output, ": keep-alive\n\n"));
        self::assertSame([7, 7], $waits, 'It pauses by the configured interval after every keep-alive.');
    }

    public function test_zero_keep_alives_only_announces_the_endpoint(): void
    {
        $output = $this->capture(new McpEventStream('/custom', 0, 1, static function (): void {
            self::fail('No pause is expected.');
        }));

        self::assertSame("event: endpoint\ndata: {\"uri\":\"\\/custom\"}\n\n", $output);
    }

    public function test_the_default_pause_really_sleeps_for_the_interval(): void
    {
        $started = microtime(true);

        $this->capture(new McpEventStream('/api/v1/mcp', 1, 0));

        self::assertLessThan(1.0, microtime(true) - $started, 'A zero-second interval must not slow the stream down.');
    }

    /** The stream flushes after every write, so chunks are collected by an output handler rather than read back. */
    private function capture(McpEventStream $stream): string
    {
        $collected = '';
        ob_start(static function (string $chunk) use (&$collected): string {
            $collected .= $chunk;

            return '';
        });
        try {
            $stream();
        } finally {
            ob_end_clean();
        }

        return $collected;
    }
}

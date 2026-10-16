<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Observability\TraceContext;
use PHPUnit\Framework\TestCase;

final class TraceContextTest extends TestCase
{
    public function test_it_generates_w3c_length_ids_when_called(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', TraceContext::newTraceId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', TraceContext::newSpanId());
    }

    public function test_it_round_trips_fields_when_header_uses_the_formatted_traceparent(): void
    {
        $traceId = str_repeat('a', 32);
        $spanId = str_repeat('b', 16);

        self::assertSame(
            ['trace_id' => $traceId, 'span_id' => $spanId, 'flags' => '01'],
            TraceContext::parseTraceparent(TraceContext::formatTraceparent($traceId, $spanId)),
        );
    }

    public function test_it_rejects_headers_when_traceparent_is_missing_or_malformed(): void
    {
        self::assertNull(TraceContext::parseTraceparent(null));
        self::assertNull(TraceContext::parseTraceparent(''));
        self::assertNull(TraceContext::parseTraceparent('00-short-short-01'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Data\Search\SearchParseResult;
use App\Services\Search\SearchParseTelemetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchParseTelemetry::class)]
#[CoversClass(SearchParseResult::class)]
final class SearchParseTelemetryTest extends TestCase
{
    public function test_a_telemetry_that_nothing_updated_reports_a_failed_parse(): void
    {
        $fields = (new SearchParseTelemetry())->toArray();

        self::assertSame('failed', $fields['parse_outcome']);
        self::assertSame('operation_failed', $fields['parse_fallback_reason']);
        self::assertSame('none', $fields['parse_backend']);
    }

    public function test_a_cache_miss_keeps_the_model_call_figures(): void
    {
        $telemetry = new SearchParseTelemetry();

        $telemetry->succeeded('ollama', true, ['ollama_chat_outcome' => 'ok', 'ollama_chat_duration_ms' => 120, 'ollama_chat_http_status' => 200, 'fallback_reason' => null]);
        $fields = $telemetry->toArray();

        self::assertSame(['success', 'miss', 'ollama', 'ok', 120, 200, null], [
            $fields['parse_outcome'], $fields['parse_cache_status'], $fields['parse_backend'], $fields['ollama_chat_outcome'],
            $fields['ollama_chat_duration_ms'], $fields['ollama_chat_http_status'], $fields['parse_fallback_reason'],
        ]);
    }

    public function test_a_cache_hit_hides_figures_of_a_call_that_was_not_made_now(): void
    {
        $telemetry = new SearchParseTelemetry();

        $telemetry->succeeded('rules', false, ['ollama_chat_outcome' => 'ok', 'ollama_chat_duration_ms' => 120, 'ollama_chat_http_status' => 200, 'fallback_reason' => 'ollama_timeout']);
        $fields = $telemetry->toArray();

        self::assertSame('hit', $fields['parse_cache_status']);
        self::assertSame('cache_hit', $fields['ollama_chat_outcome']);
        self::assertNull($fields['ollama_chat_duration_ms']);
        self::assertNull($fields['ollama_chat_http_status']);
        self::assertSame('ollama_timeout', $fields['parse_fallback_reason']);
    }

    public function test_an_unknown_backend_is_reported_as_rules(): void
    {
        $telemetry = new SearchParseTelemetry();

        $telemetry->succeeded('something-else', true, []);

        self::assertSame('rules', $telemetry->toArray()['parse_backend']);
        self::assertSame('not_attempted', $telemetry->toArray()['ollama_chat_outcome']);
    }

    public function test_an_unavailable_dependency_is_recorded_and_the_duration_is_never_negative(): void
    {
        $telemetry = new SearchParseTelemetry();

        $telemetry->dependencyUnavailable();
        $telemetry->finished(hrtime(true) + 5_000_000_000);

        self::assertSame('dependency_unavailable', $telemetry->toArray()['parse_fallback_reason']);
        self::assertSame(0, $telemetry->toArray()['parse_duration_ms']);
        $telemetry->finished(hrtime(true) - 20_000_000);
        self::assertGreaterThanOrEqual(19, $telemetry->toArray()['parse_duration_ms']);
    }

    public function test_the_parse_result_exposes_its_four_parts(): void
    {
        $result = new SearchParseResult('night', ['maker' => 'Moodyz'], ['maker' => 'Maker: Moodyz'], 'rules');

        self::assertSame(['keywords' => 'night', 'filters' => ['maker' => 'Moodyz'], 'explanations' => ['maker' => 'Maker: Moodyz'], 'by' => 'rules'], $result->toArray());
    }
}

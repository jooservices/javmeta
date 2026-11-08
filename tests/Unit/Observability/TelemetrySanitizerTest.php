<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Observability\TelemetrySanitizer;
use Tests\TestCase;

final class TelemetrySanitizerTest extends TestCase
{
    public function test_strips_denied_keys_and_nested_domain_fields(): void
    {
        $title = fake()->sentence(3);
        $code = 'STARS-' . fake()->numberBetween(100, 999);

        $clean = (new TelemetrySanitizer())->sanitizeContext([
            'source_slug' => 'javdb',
            'title' => $title,
            'code' => $code,
            'url' => 'https://example.test/' . $code,
            'nested' => [
                'error_code' => 'blocked',
                'value' => $title,
            ],
            'count' => 3,
        ]);

        $this->assertSame('javdb', $clean['source_slug']);
        $this->assertSame(3, $clean['count']);
        $this->assertArrayNotHasKey('title', $clean);
        $this->assertArrayNotHasKey('code', $clean);
        $this->assertArrayNotHasKey('url', $clean);
        $this->assertSame(['error_code' => 'blocked'], $clean['nested']);
        $this->assertStringNotContainsString($title, json_encode($clean, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($code, json_encode($clean, JSON_THROW_ON_ERROR));
    }

    public function test_crawl_detail_allow_list_drops_error_message_with_code(): void
    {
        $code = 'ABC-' . fake()->numberBetween(1000, 9999);
        $clean = (new TelemetrySanitizer())->sanitizeCrawlDetail([
            'error_code' => 'parse_failed',
            'reason' => 'unknown_source',
            'error' => 'Failed for ' . $code,
            'queued' => 2,
            'items' => 5,
            'entity_type' => 'movie',
            'url' => 'https://example.test',
        ]);

        $this->assertSame([
            'error_code' => 'parse_failed',
            'reason' => 'unknown_source',
            'queued' => 2,
            'items' => 5,
            'entity_type' => 'movie',
        ], $clean);
    }

    public function test_sanitize_url_keeps_scheme_host_and_path_only(): void
    {
        $sanitizer = new TelemetrySanitizer();

        $this->assertSame('https://example.test/v/abc', $sanitizer->sanitizeUrl('https://user:pass@example.test/v/abc?token=secret&x=1#frag'));
        $this->assertNull($sanitizer->sanitizeUrl(null));
        $this->assertNull($sanitizer->sanitizeUrl('  '));
        $this->assertSame('/relative/path', $sanitizer->sanitizeUrl('/relative/path?a=b'));
        $this->assertLessThanOrEqual(300, mb_strlen((string) $sanitizer->sanitizeUrl('https://example.test/' . str_repeat('a', 500))));
    }

    public function test_sanitize_text_masks_secrets_and_cuts_the_length(): void
    {
        $sanitizer = new TelemetrySanitizer();

        $text = (string) $sanitizer->sanitizeText('Failed: Authorization: Bearer abc123 and password=hunter2 at https://x.test/?token=zzz&a=1');
        $this->assertStringNotContainsString('abc123', $text);
        $this->assertStringNotContainsString('hunter2', $text);
        $this->assertStringNotContainsString('zzz', $text);
        $this->assertStringContainsString('[redacted]', $text);
        $this->assertSame(10, mb_strlen((string) $sanitizer->sanitizeText(str_repeat('x', 50), 10)));
        $this->assertNull($sanitizer->sanitizeText(''));
    }
}

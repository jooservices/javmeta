<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Strips domain content and secrets before telemetry leave the process.
 */
final class TelemetrySanitizer
{
    /** @var list<string> */
    private const DENY_KEYS = [
        'url',
        'title',
        'title_jp',
        'title_en',
        'code',
        'code_normalized',
        'display_code',
        'value',
        'html',
        'body',
        'token',
        'password',
        'authorization',
        'api_key',
        'plaintext',
        'cover_url',
        'cover_thumb_url',
        'description',
        'name',
        'name_kanji',
        'name_kana',
        'draft',
        'movie',
        'performer',
        'performers',
        'aliases',
        'query',
        'payload',
    ];

    /** @var list<string> */
    private const ALLOW_DETAIL_KEYS = [
        'error_code',
        'reason',
        'enqueued',
        'parsed',
        'pages',
        'count',
        'failures',
        'attempts',
        'kind',
        'status',
        'source_slug',
        'outcome',
        'queue',
        'field',
        'tier',
        'queued',
        'gap_seconds',
        'items',
        'entity_type',
        'is_new',
        'sharp_drop',
        'drop',
        'completeness_tier',
        'from',
        'to',
        'duration_ms',
        'crawl_type',
        'ok',
        'entity',
        'dependency',
        'operation',
        'dispatched',
        'reclaimed',
        'tool',
        'action',
        'page_url',
        'error_message',
        'http_status',
        'method',
        'retryable',
        'retry_after_seconds',
        'queue_row_id',
        'exception_class',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, self::DENY_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $nested = $this->sanitizeContext($value);
                if ($nested !== []) {
                    $clean[$key] = $nested;
                }

                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public function sanitizeCrawlDetail(array $detail): array
    {
        $clean = [];
        foreach ($detail as $key => $value) {
            $normalized = strtolower((string) $key);
            if (! in_array($normalized, self::ALLOW_DETAIL_KEYS, true)) {
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value) || is_string($value) || $value === null) {
                if (is_string($value) && $this->looksLikeDomainContent($value)) {
                    continue;
                }
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public function looksLikeDomainContent(string $value): bool
    {
        if (preg_match('/\b[A-Z]{2,10}-?\d{2,7}\b/', $value) === 1) {
            return true;
        }

        return strlen($value) > 120;
    }

    /**
     * A page address safe to send: scheme, host and path only (no query string, fragment or credentials), at most 300 characters.
     */
    public function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return mb_substr(preg_replace('/[?#].*$/', '', trim($url)) ?? '', 0, 300);
        }

        $clean = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');

        return mb_substr($clean, 0, 300);
    }

    /**
     * Free text (an error message, an exception message) with obvious secrets masked, cut to $max characters.
     */
    public function sanitizeText(?string $text, int $max = 500): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $masked = preg_replace(
            [
                '/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i',
                '/(authorization|bearer|cookie|set-cookie|x-api-key|api[_-]?key|token|password|passwd|secret)(["\']?\s*[:=]\s*|\s+)[^\s,;"\']+/i',
                '/([?&](?:token|key|apikey|api_key|password|secret|sig|signature)=)[^&\s"\']+/i',
            ],
            ['$1 [redacted]', '$1$2[redacted]', '$1[redacted]'],
            $text,
        ) ?? $text;

        return mb_substr($masked, 0, $max);
    }
}

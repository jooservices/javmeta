<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CrawlErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CrawlErrorCodeTest extends TestCase
{
    #[DataProvider('crawlerxCodes')]
    public function test_crawlerx_error_codes_are_preserved(string $code): void
    {
        self::assertSame($code, CrawlErrorCode::fromCrawlerx($code)->value);
    }

    public function test_missing_or_unrecognized_crawlerx_code_maps_to_unknown(): void
    {
        self::assertSame(CrawlErrorCode::Unknown, CrawlErrorCode::fromCrawlerx(null));
        self::assertSame(CrawlErrorCode::Unknown, CrawlErrorCode::fromCrawlerx('future_code'));
    }

    /** @return array<string, array{string}> */
    public static function crawlerxCodes(): array
    {
        return [
            'blocked' => ['blocked'],
            'challenge' => ['challenge'],
            'parse_failed' => ['parse_failed'],
            'unsupported_url' => ['unsupported_url'],
            'ambiguous_url' => ['ambiguous_url'],
            'adapter_not_found' => ['adapter_not_found'],
            'soft404' => ['soft404'],
            'not_found' => ['not_found'],
            'gone' => ['gone'],
            'rate_limited' => ['rate_limited'],
            'timeout' => ['timeout'],
            'network' => ['network'],
            'auth_required' => ['auth_required'],
            'ssrf_blocked' => ['ssrf_blocked'],
            'unknown' => ['unknown'],
        ];
    }
}

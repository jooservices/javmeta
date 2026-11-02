<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Services\Crawl\CrawlRetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CrawlRetryPolicyTest extends TestCase
{
    /** @return array<string, array{CrawlErrorCode}> */
    public static function terminalCodes(): array
    {
        return [
            'not found' => [CrawlErrorCode::NotFound],
            'gone' => [CrawlErrorCode::Gone],
            'parse failed' => [CrawlErrorCode::ParseFailed],
            'normalization failed' => [CrawlErrorCode::NormalizationFailed],
            'SSRF blocked' => [CrawlErrorCode::SsrfBlocked],
            'unsupported URL' => [CrawlErrorCode::UnsupportedUrl],
        ];
    }

    #[DataProvider('terminalCodes')]
    public function test_terminal_errors_are_never_retryable_even_when_adapter_marks_them_transient(CrawlErrorCode $code): void
    {
        self::assertFalse((new CrawlRetryPolicy())->shouldRetry($code, true));
    }

    public function test_network_failure_can_retry(): void
    {
        self::assertTrue((new CrawlRetryPolicy())->shouldRetry(CrawlErrorCode::Network, true));
    }
}

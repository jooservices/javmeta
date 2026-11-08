<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;

final class CrawlRetryPolicy
{
    /** @var list<CrawlErrorCode> */
    private const RETRYABLE_CODES = [
        CrawlErrorCode::Blocked,
        CrawlErrorCode::Challenge,
        CrawlErrorCode::RateLimited,
        CrawlErrorCode::Timeout,
        CrawlErrorCode::Network,
        CrawlErrorCode::WorkerFailed,
        CrawlErrorCode::WorkerLost,
    ];

    public function shouldRetry(CrawlErrorCode $errorCode, bool $retryable): bool
    {
        if (in_array($errorCode, [CrawlErrorCode::Blocked, CrawlErrorCode::Challenge], true)) {
            return true;
        }

        return $retryable && $this->isRetryableCode($errorCode);
    }

    public function isRetryableCode(CrawlErrorCode $errorCode): bool
    {
        return in_array($errorCode, self::RETRYABLE_CODES, true);
    }

    /** @return list<string> */
    public function retryableCodeValues(): array
    {
        return array_map(static fn(CrawlErrorCode $code): string => $code->value, self::RETRYABLE_CODES);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlFailureClass;

/** Gives a failed fetch attempt, as crawl_fetch_attempts records it, the verdict a person needs: expected, a real blocker or our own fault. */
final class FetchAttemptClassifier
{
    public function classify(?string $errorCode, ?int $httpStatus): CrawlFailureClass
    {
        return match (true) {
            $errorCode === 'ssrf_blocked', $errorCode === 'fetch_error' => CrawlFailureClass::OwnFault,
            $errorCode === 'challenge' => CrawlFailureClass::RealBlocker,
            in_array($httpStatus, [401, 404, 410, 429], true) => CrawlFailureClass::ExpectedOutcome,
            $httpStatus !== null && $httpStatus >= 400 && $httpStatus <= 599 => CrawlFailureClass::RealBlocker,
            default => CrawlFailureClass::Defect,
        };
    }
}

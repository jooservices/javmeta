<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Enums\CrawlFailureClass;
use App\Services\Crawl\FetchAttemptClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FetchAttemptClassifierTest extends TestCase
{
    /** @return array<string, array{?string, ?int, CrawlFailureClass}> */
    public static function attempts(): array
    {
        return [
            'page not found' => ['http_404', 404, CrawlFailureClass::ExpectedOutcome],
            'page gone' => ['http_410', 410, CrawlFailureClass::ExpectedOutcome],
            'login required' => ['http_401', 401, CrawlFailureClass::ExpectedOutcome],
            'too many requests' => ['http_429', 429, CrawlFailureClass::ExpectedOutcome],
            'challenge page' => ['challenge', 403, CrawlFailureClass::RealBlocker],
            'forbidden' => ['http_403', 403, CrawlFailureClass::RealBlocker],
            'server error' => ['http_503', 503, CrawlFailureClass::RealBlocker],
            'ssrf policy' => ['ssrf_blocked', 403, CrawlFailureClass::OwnFault],
            'no answer at all' => ['fetch_error', null, CrawlFailureClass::OwnFault],
            'transport error with a status' => ['fetch_error', 200, CrawlFailureClass::OwnFault],
            'no error code' => [null, null, CrawlFailureClass::Defect],
            'a code nobody knows' => ['something_new', null, CrawlFailureClass::Defect],
        ];
    }

    #[DataProvider('attempts')]
    public function test_an_attempt_is_classified_by_its_error_code_and_status(?string $errorCode, ?int $status, CrawlFailureClass $expected): void
    {
        self::assertSame($expected, (new FetchAttemptClassifier())->classify($errorCode, $status));
    }
}

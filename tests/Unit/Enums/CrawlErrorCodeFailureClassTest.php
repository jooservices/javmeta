<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CrawlErrorCode;
use App\Enums\CrawlFailureClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CrawlErrorCodeFailureClassTest extends TestCase
{
    /** @return array<string, array{CrawlErrorCode, CrawlFailureClass}> */
    public static function classes(): array
    {
        return [
            'not found is expected' => [CrawlErrorCode::NotFound, CrawlFailureClass::ExpectedOutcome],
            'login required is expected' => [CrawlErrorCode::AuthRequired, CrawlFailureClass::ExpectedOutcome],
            'rate limit is expected' => [CrawlErrorCode::RateLimited, CrawlFailureClass::ExpectedOutcome],
            'challenge is a real blocker' => [CrawlErrorCode::Challenge, CrawlFailureClass::RealBlocker],
            'blocked is a real blocker' => [CrawlErrorCode::Blocked, CrawlFailureClass::RealBlocker],
            'network is our own fault' => [CrawlErrorCode::Network, CrawlFailureClass::OwnFault],
            'timeout is our own fault' => [CrawlErrorCode::Timeout, CrawlFailureClass::OwnFault],
            'ssrf is our own fault' => [CrawlErrorCode::SsrfBlocked, CrawlFailureClass::OwnFault],
            'parse failure is a defect' => [CrawlErrorCode::ParseFailed, CrawlFailureClass::Defect],
            'unknown is a defect' => [CrawlErrorCode::Unknown, CrawlFailureClass::Defect],
        ];
    }

    #[DataProvider('classes')]
    public function test_an_error_code_has_the_expected_failure_class(CrawlErrorCode $code, CrawlFailureClass $class): void
    {
        self::assertSame($class, $code->failureClass());
    }

    public function test_every_error_code_has_a_class_and_expected_outcomes_agree_with_it(): void
    {
        foreach (CrawlErrorCode::cases() as $code) {
            self::assertSame(
                $code->isExpectedOutcome(),
                $code->failureClass() === CrawlFailureClass::ExpectedOutcome,
                $code->value,
            );
        }
    }
}

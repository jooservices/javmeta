<?php

declare(strict_types=1);

namespace Tests\Feature\Exceptions;

use App\Exceptions\BulkLimitExceededException;
use App\Exceptions\DependencyUnavailableException;
use App\Exceptions\ForbiddenScopeException;
use App\Exceptions\InvalidFilterException;
use App\Exceptions\MovieNotFoundException;
use App\Exceptions\PerformerNotFoundException;
use App\Exceptions\RateLimitedException;
use App\Exceptions\UnauthorizedException;
use App\Services\Dependencies\Dependency;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class ClientErrorReportingTest extends TestCase
{
    /** @return array<string, array{Throwable}> */
    public static function expectedClientErrors(): array
    {
        return [
            'missing or invalid API key' => [new UnauthorizedException()],
            'API key without the scope' => [new ForbiddenScopeException('movies')],
            'invalid filter' => [new InvalidFilterException('Invalid movie search filter.')],
            'unknown movie' => [new MovieNotFoundException('ABC-123')],
            'unknown performer' => [new PerformerNotFoundException('Nobody')],
            'rate limited caller' => [new RateLimitedException(30)],
            'bulk lookup over the limit' => [new BulkLimitExceededException()],
        ];
    }

    #[DataProvider('expectedClientErrors')]
    public function test_ac1_an_expected_client_error_is_not_reported(Throwable $exception): void
    {
        self::assertFalse(app(ExceptionHandler::class)->shouldReport($exception));
    }

    public function test_ac3_a_server_side_problem_and_an_unexpected_failure_are_still_reported(): void
    {
        $handler = app(ExceptionHandler::class);

        self::assertTrue($handler->shouldReport(new DependencyUnavailableException(Dependency::Elasticsearch)));
        self::assertTrue($handler->shouldReport(new RuntimeException('unexpected')));
    }
}

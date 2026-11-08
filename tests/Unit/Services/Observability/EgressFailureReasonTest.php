<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Observability;

use App\Services\Observability\EgressFailureReason;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(EgressFailureReason::class)]
final class EgressFailureReasonTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function playwrightOutputs(): iterable
    {
        yield 'no output' => ['', 'Playwright fetch exited with code 3.'];
        yield 'blank output' => ["  \n ", 'Playwright fetch exited with code 3.'];
        yield 'not a string' => [null, 'Playwright fetch exited with code 3.'];
        yield 'timeout' => ['Navigation Timeout of 5000ms exceeded', 'Playwright fetch timed out (exit code 3).'];
        yield 'timed out' => ['request timed out', 'Playwright fetch timed out (exit code 3).'];
        yield 'launch failed' => ['Browser launch failed: no display', 'Playwright browser could not start (exit code 3).'];
        yield 'could not launch' => ['could not launch chromium', 'Playwright browser could not start (exit code 3).'];
        yield 'blocked target' => ['SSRF_BLOCKED for 10.0.0.1', 'Playwright fetch target was blocked (exit code 3).'];
        yield 'dns failure' => ['net::ERR_NAME_NOT_RESOLVED', 'Playwright could not resolve the echo host (exit code 3).'];
        yield 'could not resolve' => ['Could not resolve host', 'Playwright could not resolve the echo host (exit code 3).'];
        yield 'refused' => ['net::ERR_CONNECTION_REFUSED at http://1.2.3.4/', 'Playwright could not connect to the echo host (exit code 3).'];
        yield 'connection refused text' => ['connection refused', 'Playwright could not connect to the echo host (exit code 3).'];
        yield 'anything else' => ["weird\x00 failure\n here", 'Playwright fetch failed (exit code 3).'];
    }

    #[DataProvider('playwrightOutputs')]
    public function test_playwright_output_becomes_a_short_reason_with_the_exit_code(mixed $stderr, string $expected): void
    {
        self::assertSame($expected, (new EgressFailureReason())->playwright(3, $stderr));
    }

    public function test_urls_in_the_playwright_output_never_reach_the_reason(): void
    {
        $reason = (new EgressFailureReason())->playwright(1, 'failed to open https://secret.internal/path?token=abc timeout');

        self::assertStringNotContainsString('secret.internal', $reason);
        self::assertStringNotContainsString('token', $reason);
    }

    /** @return iterable<string, array{\Throwable, string}> */
    public static function exceptions(): iterable
    {
        yield 'timeout' => [new RuntimeException('cURL error 28: Operation timeout'), 'Browser service request timed out.'];
        yield 'timed out' => [new RuntimeException('Connection timed out after 5000 ms'), 'Browser service request timed out.'];
        yield 'refused' => [new RuntimeException('Connection refused'), 'Browser service refused the connection.'];
        yield 'unresolvable host' => [new RuntimeException('Could not resolve host: flare.internal'), 'Browser service host could not be resolved.'];
        yield 'unknown name' => [new RuntimeException('Name or service not known'), 'Browser service host could not be resolved.'];
        yield 'something else' => [new InvalidArgumentException('bad'), 'Browser service request failed (InvalidArgumentException).'];
    }

    #[DataProvider('exceptions')]
    public function test_a_transport_exception_becomes_a_short_reason_without_its_message(\Throwable $exception, string $expected): void
    {
        self::assertSame($expected, (new EgressFailureReason())->exception($exception));
    }
}

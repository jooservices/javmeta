<?php

declare(strict_types=1);

namespace Tests\Unit\Observability\Sentry;

use App\Observability\Sentry\CrawlFailureReporter;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Tests\TestCase;

final class CrawlFailureReporterTest extends TestCase
{
    public function test_report_captures_one_exception_with_tags_and_fingerprint(): void
    {
        config()->set(['sentry.role' => 'worker', 'sentry.node' => 'crawl-a']);
        $exception = new RuntimeException('crawl failed');
        $client = Mockery::mock(ClientInterface::class);
        $hub = Mockery::mock(HubInterface::class);
        $scope = Mockery::mock(Scope::class);
        $scope->shouldReceive('setTag')->once()->with('role', 'worker')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('node', 'crawl-a')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('source', 'sample-source')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('crawl_kind', 'detail')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('error_code', 'worker_failed')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('failure_class', 'own_fault')->andReturnSelf();
        $scope->shouldReceive('setFingerprint')->once()->with(['worker_failed', 'sample-source'])->andReturnSelf();

        $hub->shouldReceive('getClient')->once()->andReturn($client);
        $hub->shouldReceive('withScope')->once()->andReturnUsing(function (callable $callback) use ($scope): void {
            $callback($scope);
        });
        $hub->shouldReceive('captureException')->once()->with($exception)->andReturn(null);

        (new CrawlFailureReporter($hub))->report($exception, 'sample-source', 'detail', 'worker_failed');
    }

    /** @return array<string, array{string}> */
    public static function expectedCrawlOutcomes(): array
    {
        return [
            'page not found' => ['not_found'],
            'page gone' => ['gone'],
            'soft 404' => ['soft404'],
            'login required' => ['auth_required'],
            'rate limited by the site' => ['rate_limited'],
        ];
    }

    #[DataProvider('expectedCrawlOutcomes')]
    public function test_ac2_an_expected_crawl_outcome_is_not_captured_and_nothing_captures_it_later(string $errorCode): void
    {
        $exception = new RuntimeException('Terminal fetch status for URL [https://example.test/x].');
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->once()->andReturn(Mockery::mock(ClientInterface::class));
        $hub->shouldNotReceive('withScope');
        $hub->shouldNotReceive('captureException');

        (new CrawlFailureReporter($hub))->report($exception, 'sample-source', 'detail', $errorCode);

        self::assertTrue(CrawlFailureReporter::wasReported($exception));
    }

    public function test_report_skips_when_sentry_client_is_not_bound(): void
    {
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->once()->andReturn(null);
        $hub->shouldNotReceive('withScope');
        $hub->shouldNotReceive('captureException');

        (new CrawlFailureReporter($hub))->report(new RuntimeException('crawl failed'), 'sample-source', 'detail', 'worker_failed');
    }

    public function test_ssrf_failures_attach_reason_and_address_context_without_changing_fingerprint(): void
    {
        config()->set(['sentry.role' => 'worker', 'sentry.node' => 'crawl-a']);
        $exception = new RuntimeException(
            'Fetch blocked by SSRF policy for URL [https://en.jable.tv/models/x/]. reason=no_public_address address=127.0.0.1',
        );
        $client = Mockery::mock(ClientInterface::class);
        $hub = Mockery::mock(HubInterface::class);
        $scope = Mockery::mock(Scope::class);
        $scope->shouldReceive('setTag')->once()->with('role', 'worker')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('node', 'crawl-a')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('source', 'jable')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('crawl_kind', 'performer_listing')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('error_code', 'ssrf_blocked')->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('failure_class', 'own_fault')->andReturnSelf();
        $scope->shouldReceive('setFingerprint')->once()->with(['ssrf_blocked', 'jable'])->andReturnSelf();
        $scope->shouldReceive('setContext')->once()->with('ssrf', [
            'reason' => 'no_public_address',
            'address' => '127.0.0.1',
        ])->andReturnSelf();
        $scope->shouldReceive('setTag')->once()->with('ssrf_reason', 'no_public_address')->andReturnSelf();

        $hub->shouldReceive('getClient')->once()->andReturn($client);
        $hub->shouldReceive('withScope')->once()->andReturnUsing(function (callable $callback) use ($scope): void {
            $callback($scope);
        });
        $hub->shouldReceive('captureException')->once()->with($exception)->andReturn(null);

        (new CrawlFailureReporter($hub))->report($exception, 'jable', 'performer_listing', 'ssrf_blocked');
    }

    public function test_ac3_two_failures_of_a_real_blocker_on_different_urls_share_one_fingerprint_and_carry_the_class(): void
    {
        config()->set(['sentry.role' => 'worker', 'sentry.node' => 'crawl-a']);
        $fingerprints = [];
        $classes = [];
        $scope = Mockery::mock(Scope::class);
        $scope->shouldReceive('setTag')->andReturnUsing(static function (string $key, string $value) use (&$classes, $scope): Scope {
            if ($key === 'failure_class') {
                $classes[] = $value;
            }

            return $scope;
        });
        $scope->shouldReceive('setFingerprint')->andReturnUsing(static function (array $fingerprint) use (&$fingerprints, $scope): Scope {
            $fingerprints[] = $fingerprint;

            return $scope;
        });
        $scope->shouldReceive('setLevel')->andReturnSelf();
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->andReturn(Mockery::mock(ClientInterface::class));
        $hub->shouldReceive('withScope')->andReturnUsing(static function (callable $callback) use ($scope): void {
            $callback($scope);
        });
        $hub->shouldReceive('captureException')->twice()->andReturn(null);

        $reporter = new CrawlFailureReporter($hub);
        $reporter->report(new RuntimeException('All fetch methods exhausted for URL [https://example.test/a].'), 'example', 'listing', 'blocked');
        $reporter->report(new RuntimeException('All fetch methods exhausted for URL [https://example.test/b].'), 'example', 'detail', 'blocked');

        self::assertSame([['blocked', 'example'], ['blocked', 'example']], $fingerprints);
        self::assertSame(['real_blocker', 'real_blocker'], $classes);
    }
}

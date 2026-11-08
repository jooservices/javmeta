<?php

declare(strict_types=1);

namespace Tests\Unit\Observability\Sentry;

use App\Observability\Sentry\CrawlFailureReporter;
use Mockery;
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
        $scope->shouldReceive('setFingerprint')->once()->with(['worker_failed', 'sample-source'])->andReturnSelf();

        $hub->shouldReceive('getClient')->once()->andReturn($client);
        $hub->shouldReceive('withScope')->once()->andReturnUsing(function (callable $callback) use ($scope): void {
            $callback($scope);
        });
        $hub->shouldReceive('captureException')->once()->with($exception)->andReturn(null);

        (new CrawlFailureReporter($hub))->report($exception, 'sample-source', 'detail', 'worker_failed');
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
}

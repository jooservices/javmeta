<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Archive;

use App\Services\Archive\MongoArchiveFailureReporter;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Sentry\ClientInterface;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Tests\TestCase;

#[CoversClass(MongoArchiveFailureReporter::class)]
final class MongoArchiveFailureReporterTest extends TestCase
{
    public function test_the_failure_is_always_logged_without_the_driver_message(): void
    {
        Log::shouldReceive('error')->once()->with('Mongo archive write failed.', [
            'error_code' => 'mongo_archive_write_failed',
            'entity_type' => 'movie',
            'site_slug' => 'javdb',
            'exception_type' => RuntimeException::class,
        ]);
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->once()->andReturn(null);

        (new MongoArchiveFailureReporter($hub))->report(new RuntimeException('mongodb://user:secret@host failed'), 'javdb', 'movie');
    }

    public function test_with_sentry_it_sends_a_generic_exception_inside_a_tagged_scope(): void
    {
        Log::shouldReceive('error')->once();
        $scope = new Scope();
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->andReturn(Mockery::mock(ClientInterface::class));
        $hub->shouldReceive('withScope')->once()->andReturnUsing(static function (callable $callback) use ($scope): void {
            $callback($scope);
        });
        $captured = null;
        $hub->shouldReceive('captureException')->once()->andReturnUsing(static function (\Throwable $exception) use (&$captured): null {
            $captured = $exception;

            return null;
        });

        (new MongoArchiveFailureReporter($hub))->report(new RuntimeException('mongodb://user:secret@host failed'), 'javdb', 'performer');

        self::assertInstanceOf(RuntimeException::class, $captured);
        self::assertSame('Mongo archive write failed.', $captured->getMessage(), 'The driver message may contain connection details.');
    }

    public function test_a_failing_sentry_report_is_logged_and_never_breaks_the_crawl(): void
    {
        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once()->with('Mongo archive failure could not be sent to Sentry.', [
            'error_code' => 'mongo_archive_sentry_report_failed',
            'exception_type' => RuntimeException::class,
        ]);
        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('getClient')->andReturn(Mockery::mock(ClientInterface::class));
        $hub->shouldReceive('withScope')->andThrow(new RuntimeException('sentry down'));

        (new MongoArchiveFailureReporter($hub))->report(new RuntimeException('x'), 'javdb', 'movie');

        $this->addToAssertionCount(1);
    }
}

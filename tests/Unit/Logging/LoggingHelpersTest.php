<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\ArrayLogStore;
use App\Logging\CreateOpenObserveLogger;
use App\Logging\OpenObserveLogHandler;
use JOOservices\LaravelLogging\DTO\ActivityLogData;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(CreateOpenObserveLogger::class)]
#[CoversClass(ArrayLogStore::class)]
final class LoggingHelpersTest extends TestCase
{
    public function test_the_openobserve_logger_has_one_handler_at_the_configured_level(): void
    {
        $logger = (new CreateOpenObserveLogger())(['level' => 'warning']);

        self::assertInstanceOf(Logger::class, $logger);
        self::assertSame('openobserve', $logger->getName());
        self::assertCount(1, $logger->getHandlers());
        self::assertInstanceOf(OpenObserveLogHandler::class, $logger->getHandlers()[0]);
        self::assertFalse($logger->getHandlers()[0]->isHandling(new \Monolog\LogRecord(new \DateTimeImmutable(), 'x', \Monolog\Level::Info, 'm')));
        self::assertTrue($logger->getHandlers()[0]->isHandling(new \Monolog\LogRecord(new \DateTimeImmutable(), 'x', \Monolog\Level::Error, 'm')));
    }

    public function test_the_openobserve_logger_defaults_to_the_debug_level(): void
    {
        $handler = (new CreateOpenObserveLogger())([])->getHandlers()[0];

        self::assertTrue($handler->isHandling(new \Monolog\LogRecord(new \DateTimeImmutable(), 'x', \Monolog\Level::Debug, 'm')));
    }

    public function test_the_array_store_keeps_records_in_order_until_flushed(): void
    {
        $store = new ArrayLogStore();
        $first = $this->data('first');
        $second = $this->data('second');

        $store->record($first);
        $saved = $store->recordMany([$second]);

        self::assertCount(1, $saved);
        self::assertSame([$first, $second], $store->all());

        $store->flush();
        self::assertSame([], $store->all());
        self::assertSame($first, $store->prepare($first));
    }

    private function data(string $action): ActivityLogData
    {
        return new ActivityLogData(
            uuid: $action . '-uuid',
            type: 'activity',
            adapter: 'array',
            level: null,
            action: $action,
            message: null,
            actorType: null,
            actorId: null,
            subjectType: null,
            subjectId: null,
            causerType: null,
            causerId: null,
            source: null,
            sourceType: null,
            requestId: null,
            correlationId: null,
            traceId: null,
            ipAddress: null,
            userAgent: null,
            tenantId: null,
            properties: [],
            context: [],
            changes: [],
            occurredAt: null,
        );
    }
}

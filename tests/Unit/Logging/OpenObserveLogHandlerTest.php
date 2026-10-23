<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\OpenObserveLogHandler;
use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use DateTimeImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

final class OpenObserveLogHandlerTest extends TestCase
{
    public function test_an_error_with_an_exception_is_sent_with_its_cause_file_line_and_trace(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);
        $handler = new OpenObserveLogHandler(app(OpenObserveClient::class), new TelemetrySanitizer());

        $handler->handle(new LogRecord(
            new DateTimeImmutable(),
            'staging',
            Level::Error,
            'Job failed',
            ['exception' => new RuntimeException('boom with password=hunter2')],
        ));

        Http::assertSent(static function (ClientRequest $request): bool {
            $record = $request->data()[0] ?? [];

            return ($record['level'] ?? null) === 'ERROR'
                && ($record['exception_class'] ?? null) === RuntimeException::class
                && str_contains((string) ($record['exception_message'] ?? ''), 'boom')
                && ! str_contains($request->body(), 'hunter2')
                && is_int($record['exception_line'] ?? null)
                && str_contains((string) ($record['exception_file'] ?? ''), 'OpenObserveLogHandlerTest.php')
                && ($record['exception_trace'] ?? '') !== '';
        });
    }

    public function test_a_record_without_an_exception_has_no_exception_fields(): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
        ]);
        Http::fake(['openobserve.test/*' => Http::response([], 200)]);
        $handler = new OpenObserveLogHandler(app(OpenObserveClient::class), new TelemetrySanitizer());

        $handler->handle(new LogRecord(new DateTimeImmutable(), 'staging', Level::Info, 'hello', []));

        Http::assertSent(static fn(ClientRequest $request): bool => ! array_key_exists('exception_class', $request->data()[0] ?? []));
    }
}

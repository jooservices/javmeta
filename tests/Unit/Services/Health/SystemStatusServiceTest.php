<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Health\SystemStatusService;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(SystemStatusService::class)]
final class SystemStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'elasticsearch.host' => 'http://es.test:9200',
            'elasticsearch.embedder_url' => 'http://embedder.test:8000',
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://oo.test:5080',
            'database.connections.mongodb.dsn' => '',
        ]);
        $this->useDatabase(true);
    }

    public function test_everything_reachable_is_ok_and_the_disabled_services_are_marked_disabled(): void
    {
        config(['openobserve.enabled' => false]);
        $this->fakeHttp(['es.test:9200/*' => Http::response([], 200), 'embedder.test:8000/*' => Http::response([], 200)]);

        $status = $this->service()->status();

        self::assertSame('ok', $status['status']);
        self::assertNotEmpty($status['checked_at']);
        self::assertSame(['status' => 'ok'], $status['services']['database']);
        self::assertSame(['status' => 'ok'], $status['services']['elasticsearch']);
        self::assertSame(['status' => 'ok'], $status['services']['embedder']);
        self::assertSame(['status' => 'disabled'], $status['services']['activity_log']);
        self::assertSame(['status' => 'disabled'], $status['services']['observability']);
    }

    public function test_a_healthy_openobserve_is_reported_ok(): void
    {
        $this->fakeHttp(['*' => Http::response([], 200)]);

        self::assertSame(['status' => 'ok'], $this->service()->status()['services']['observability']);
    }

    public function test_failing_optional_services_degrade_the_overall_status(): void
    {
        $this->fakeHttp([
            'es.test:9200/*' => Http::response([], 503),
            'embedder.test:8000/*' => Http::response([], 500),
            'oo.test:5080/*' => Http::response([], 502),
        ]);

        $status = $this->service()->status();

        self::assertSame('degraded', $status['status']);
        self::assertSame(['status' => 'down'], $status['services']['elasticsearch']);
        self::assertSame(['status' => 'down'], $status['services']['embedder']);
        self::assertSame(['status' => 'down'], $status['services']['observability']);
    }

    public function test_a_transport_failure_is_reported_with_its_message_cut_to_two_hundred_characters(): void
    {
        $this->fakeHttp(static fn() => throw new ConnectionException(str_repeat('x', 500)));

        $services = $this->service()->status()['services'];

        foreach (['elasticsearch', 'embedder', 'observability'] as $name) {
            self::assertSame('down', $services[$name]['status'], $name);
            self::assertSame(200, mb_strlen($services[$name]['detail']), $name);
        }
    }

    public function test_an_exception_without_a_message_is_reported_by_its_class_name(): void
    {
        $this->fakeHttp(static fn() => throw new RuntimeException(''));

        self::assertSame(RuntimeException::class, $this->service()->status()['services']['embedder']['detail']);
    }

    public function test_a_database_that_cannot_connect_makes_the_whole_system_down(): void
    {
        $this->useDatabase(false);
        $this->fakeHttp(['*' => Http::response([], 200)]);

        $status = $this->service()->status();

        self::assertSame('down', $status['status']);
        self::assertSame('down', $status['services']['database']['status']);
        self::assertSame('no database', $status['services']['database']['detail']);
    }

    public function test_an_unreachable_activity_log_is_down_while_a_blank_or_malformed_dsn_means_disabled(): void
    {
        $this->fakeHttp(['*' => Http::response([], 200)]);

        config(['database.connections.mongodb.dsn' => 'mongodb://127.0.0.1:1/?serverSelectionTimeoutMS=100']);
        $down = $this->service()->status();
        self::assertSame('down', $down['services']['activity_log']['status']);
        self::assertSame('degraded', $down['status']);

        config(['database.connections.mongodb.dsn' => '']);
        self::assertSame('disabled', $this->service()->status()['services']['activity_log']['status']);
    }

    private function service(): SystemStatusService
    {
        return app(SystemStatusService::class);
    }

    /** @param mixed $stubs */
    private function fakeHttp(mixed $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($stubs);
    }

    private function useDatabase(bool $reachable): void
    {
        $connection = \Mockery::mock(Connection::class);
        $reachable
            ? $connection->shouldReceive('getPdo')->andReturn(new \stdClass())
            : $connection->shouldReceive('getPdo')->andThrow(new RuntimeException('no database'));
        $manager = \Mockery::mock();
        $manager->shouldReceive('connection')->andReturn($connection);
        DB::swap($manager);
    }
}

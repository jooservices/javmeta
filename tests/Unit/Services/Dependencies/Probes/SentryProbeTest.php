<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\SentryProbe;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

final class SentryProbeTest extends TestCase
{
    public function test_it_checks_the_sentry_host_without_exposing_dsn_credentials(): void
    {
        config(['sentry.dsn' => 'https://test-key@sentry.example.test/1']);
        Http::fake(static fn() => Http::response([], 200));

        $result = (new SentryProbe())->check();

        self::assertTrue($result->available);
        self::assertStringNotContainsString('test-key', $result->message);
    }

    public function test_it_reports_sentry_as_disabled_without_a_dsn(): void
    {
        config(['sentry.dsn' => '']);

        self::assertNull((new SentryProbe())->check()->available);
    }

    public function test_a_server_error_or_a_transport_failure_is_down(): void
    {
        config(['sentry.dsn' => 'https://public@sentry.example.test:9000/1']);
        Http::fake(['sentry.example.test:9000*' => Http::response('', 503)]);
        self::assertFalse((new SentryProbe())->check()->available);
        self::assertFalse((new SentryProbe())->probe());

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(static fn() => throw new ConnectionException('refused'));
        self::assertFalse((new SentryProbe())->check()->available);
    }

    public function test_a_client_error_still_counts_as_a_reachable_host(): void
    {
        config(['sentry.dsn' => 'https://public@sentry.example.test/1']);
        Http::fake(['sentry.example.test*' => Http::response('', 404)]);

        self::assertTrue((new SentryProbe())->check()->available);
    }
}

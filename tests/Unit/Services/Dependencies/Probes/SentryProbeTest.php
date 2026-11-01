<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\SentryProbe;
use Illuminate\Support\Facades\Http;
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
}

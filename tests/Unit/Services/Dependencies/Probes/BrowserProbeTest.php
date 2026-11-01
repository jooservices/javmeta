<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dependencies\Probes;

use App\Services\Dependencies\Probes\BrowserProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class BrowserProbeTest extends TestCase
{
    public function test_it_checks_every_configured_browser_endpoint(): void
    {
        config([
            'javmeta_health.startup.flaresolverr_url' => 'http://flare.example.test/v1',
            'javmeta_health.startup.playwright_url' => 'http://browser.example.test',
        ]);
        Http::fake(static fn($request) => Http::response([], str_contains($request->url(), 'flare.example.test') ? 405 : 200));

        self::assertTrue((new BrowserProbe())->probe());
        Http::assertSentCount(2);
    }

    public function test_it_reports_unconfigured_browser_services_as_disabled(): void
    {
        config(['javmeta_health.startup.flaresolverr_url' => '', 'javmeta_health.startup.playwright_url' => '']);

        self::assertNull((new BrowserProbe())->check()->available);
    }
}

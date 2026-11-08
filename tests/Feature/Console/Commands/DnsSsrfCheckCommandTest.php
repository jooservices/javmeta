<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Observability\BrowserDnsHealthProbe;
use App\Services\Observability\SsrfDnsAnswerClassifier;
use Tests\TestCase;

final class DnsSsrfCheckCommandTest extends TestCase
{
    public function test_command_reports_success_when_every_host_keeps_a_public_pin(): void
    {
        $this->app->instance(BrowserDnsHealthProbe::class, new BrowserDnsHealthProbe(
            new SsrfDnsAnswerClassifier(),
            static fn(string $host): array => match ($host) {
                'en.jable.tv' => ['127.0.0.1', '104.20.42.172'],
                'missav.ws' => ['104.20.31.186'],
                default => [],
            },
        ));

        $this->artisan('crawler:dns-ssrf-check')
            ->expectsOutputToContain('All checked hosts have at least one public address')
            ->assertSuccessful();
    }

    public function test_command_fails_when_a_host_has_no_public_pin_and_accepts_custom_hosts(): void
    {
        $this->app->instance(BrowserDnsHealthProbe::class, new BrowserDnsHealthProbe(
            new SsrfDnsAnswerClassifier(),
            static fn(string $host): array => match ($host) {
                'sinkhole.example' => ['127.0.0.1', '0.0.0.0'],
                default => ['8.8.8.8'],
            },
        ));

        $this->artisan('crawler:dns-ssrf-check', ['--host' => ['sinkhole.example']])
            ->expectsOutputToContain('no public DNS pin')
            ->assertFailed();
    }
}

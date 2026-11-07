<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Observability;

use App\Services\Observability\BrowserDnsHealthProbe;
use App\Services\Observability\SsrfDnsAnswerClassifier;
use Tests\TestCase;

final class BrowserDnsHealthProbeTest extends TestCase
{
    public function test_check_fails_when_any_host_has_no_public_pin(): void
    {
        $probe = new BrowserDnsHealthProbe(
            new SsrfDnsAnswerClassifier(),
            static function (string $host): array {
                return match ($host) {
                    'en.jable.tv' => ['127.0.0.1', '0.0.0.0', '104.20.42.172'],
                    'missav.ws' => ['127.0.0.1', '0.0.0.0'],
                    default => [],
                };
            },
        );

        $result = $probe->check(['en.jable.tv', 'missav.ws']);

        self::assertFalse($result['ok']);
        self::assertTrue($result['hosts'][0]['safe']);
        self::assertFalse($result['hosts'][1]['safe']);
        self::assertSame('no_public_address', $result['hosts'][1]['reason']);
    }

    public function test_check_passes_when_every_host_keeps_a_public_pin_after_decoy_filter(): void
    {
        $probe = new BrowserDnsHealthProbe(
            new SsrfDnsAnswerClassifier(),
            static fn(string $host): array => match ($host) {
                'en.jable.tv' => ['127.0.0.1', '104.20.42.172', '172.66.167.218'],
                'missav.ws' => ['104.20.31.186', '172.66.167.65'],
                default => [],
            },
        );

        $result = $probe->check(['en.jable.tv', 'missav.ws']);

        self::assertTrue($result['ok']);
        self::assertSame(['104.20.42.172', '172.66.167.218'], $result['hosts'][0]['public']);
    }

    public function test_check_skips_blank_hosts_and_treats_lookup_failures_as_empty_answers(): void
    {
        $probe = new BrowserDnsHealthProbe(
            new SsrfDnsAnswerClassifier(),
            static function (string $host): array {
                if ($host === 'throws.example') {
                    throw new \RuntimeException('resolver down');
                }

                return ['8.8.8.8'];
            },
        );

        $result = $probe->check(['', 'throws.example', 'ok.example']);

        self::assertCount(2, $result['hosts']);
        self::assertFalse($result['ok']);
        self::assertSame('throws.example', $result['hosts'][0]['host']);
        self::assertSame('dns_failed', $result['hosts'][0]['reason']);
        self::assertTrue($result['hosts'][1]['safe']);
    }

    public function test_default_lookup_returns_unique_addresses_and_tolerates_resolver_failure(): void
    {
        $real = new BrowserDnsHealthProbe(new SsrfDnsAnswerClassifier());
        $method = new \ReflectionMethod(BrowserDnsHealthProbe::class, 'defaultLookup');

        $addresses = $method->invoke($real, 'localhost');
        self::assertIsArray($addresses);
        self::assertSame($addresses, array_values(array_unique($addresses)));

        // An empty hostname makes dns_get_record fail on PHP, which must become [].
        self::assertSame([], $method->invoke($real, ''));
    }
}

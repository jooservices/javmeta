<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Observability;

use App\Services\Observability\SsrfDnsAnswerClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SsrfDnsAnswerClassifierTest extends TestCase
{
    private SsrfDnsAnswerClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new SsrfDnsAnswerClassifier();
    }

    public function test_production_jable_decoy_answers_keep_public_cloudflare_pins(): void
    {
        $result = $this->classifier->classify([
            '127.0.0.1',
            '0.0.0.0',
            '104.20.42.172',
            '172.66.167.218',
        ]);

        self::assertTrue($result['safe']);
        self::assertSame(['104.20.42.172', '172.66.167.218'], $result['public']);
        self::assertSame(['127.0.0.1', '0.0.0.0'], $result['blocked']);
        self::assertNull($result['reason']);
    }

    public function test_production_missav_public_answers_are_safe(): void
    {
        $result = $this->classifier->classify(['104.20.31.186', '172.66.167.65']);

        self::assertTrue($result['safe']);
        self::assertSame(['104.20.31.186', '172.66.167.65'], $result['public']);
    }

    public function test_sinkhole_only_answers_are_blocked(): void
    {
        $result = $this->classifier->classify(['127.0.0.1', '0.0.0.0', '::1']);

        self::assertFalse($result['safe']);
        self::assertSame('no_public_address', $result['reason']);
        self::assertSame([], $result['public']);
    }

    #[DataProvider('blockedAddressProvider')]
    public function test_blocked_special_use_and_private_ranges(string $address): void
    {
        self::assertTrue($this->classifier->isBlocked($address), $address);
        $result = $this->classifier->classify([$address]);
        self::assertFalse($result['safe']);
        self::assertSame('no_public_address', $result['reason']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function blockedAddressProvider(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback_net' => ['127.1.2.3'];
        yield 'rfc1918_10' => ['10.0.0.1'];
        yield 'rfc1918_172' => ['172.16.5.5'];
        yield 'rfc1918_192' => ['192.168.1.1'];
        yield 'link_local' => ['169.254.1.1'];
        yield 'metadata' => ['169.254.169.254'];
        yield 'cgnat' => ['100.64.0.1'];
        yield 'benchmark' => ['198.18.0.1'];
        yield 'unspecified' => ['0.0.0.0'];
        yield 'ipv6_loopback' => ['::1'];
        yield 'ipv6_ula' => ['fc00::1'];
        yield 'ipv4_mapped_loopback' => ['::ffff:127.0.0.1'];
        yield 'ipv4_mapped_private' => ['::ffff:10.0.0.1'];
        yield 'ipv4_mapped_metadata' => ['::ffff:169.254.169.254'];
    }

    public function test_empty_answers_are_dns_failed(): void
    {
        $result = $this->classifier->classify([]);

        self::assertFalse($result['safe']);
        self::assertSame('dns_failed', $result['reason']);
    }

    public function test_blank_and_non_ip_answers_are_ignored_or_blocked(): void
    {
        $result = $this->classifier->classify(['', 'not-an-ip', '8.8.8.8']);

        self::assertTrue($result['safe']);
        self::assertSame(['8.8.8.8'], $result['public']);
        self::assertSame(['not-an-ip'], $result['blocked']);
        self::assertTrue($this->classifier->isBlocked('not-an-ip'));
    }

    #[DataProvider('additionalBlockedAddressProvider')]
    public function test_additional_special_use_ranges_are_blocked(string $address): void
    {
        self::assertTrue($this->classifier->isBlocked($address), $address);
        $result = $this->classifier->classify([$address, '1.1.1.1']);
        self::assertTrue($result['safe']);
        self::assertContains($address, $result['blocked']);
        self::assertSame(['1.1.1.1'], $result['public']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function additionalBlockedAddressProvider(): iterable
    {
        yield 'ietf_protocol' => ['192.0.0.1'];
        yield 'documentation_testnet' => ['192.0.2.1'];
        yield 'six_to_four_relay' => ['192.88.99.1'];
        yield 'documentation_testnet_2' => ['198.51.100.1'];
        yield 'documentation_testnet_3' => ['203.0.113.1'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reserved' => ['240.0.0.1'];
        yield 'ipv6_documentation' => ['2001:db8::1'];
        yield 'ipv6_teredo' => ['2001:0::1'];
        yield 'ipv6_6to4' => ['2002::1'];
        yield 'ipv6_nat64' => ['64:ff9b::1'];
        yield 'ipv6_discard' => ['100::1'];
        yield 'ipv6_link_local' => ['fe80::1'];
        yield 'ipv6_multicast' => ['ff02::1'];
    }
}

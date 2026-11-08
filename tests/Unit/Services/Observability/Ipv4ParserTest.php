<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Observability;

use App\Services\Observability\Ipv4Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ipv4Parser::class)]
final class Ipv4ParserTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function bodies(): iterable
    {
        yield 'plain address' => ['203.0.114.7', '203.0.114.7'];
        yield 'with whitespace' => ["  8.8.8.8\n", '8.8.8.8'];
        yield 'inside a page' => ['<html><body><pre>1.1.1.1</pre></body></html>', '1.1.1.1'];
        yield 'script and style are ignored' => ['<style>p{}</style><script>var x="9.9.9.9";</script>8.8.4.4', '8.8.4.4'];
        yield 'html entities are decoded' => ['&#49;.1.1.1', '1.1.1.1'];
        yield 'text around the address' => ['Your IP is 8.8.8.8', null];
        yield 'ipv6' => ['2001:4860:4860::8888', null];
        yield 'out of range octet' => ['256.1.1.1', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('bodies')]
    public function test_it_reads_a_bare_ipv4_address_out_of_a_response_body(string $body, ?string $expected): void
    {
        self::assertSame($expected, (new Ipv4Parser())->parse($body));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public' => ['8.8.8.8', true];
        yield 'private 10/8' => ['10.1.2.3', false];
        yield 'private 192.168/16' => ['192.168.1.20', false];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'link local' => ['169.254.1.1', false];
        yield 'carrier grade nat' => ['100.64.0.1', false];
        yield 'carrier grade nat upper edge' => ['100.127.255.254', false];
        yield 'just outside carrier grade nat' => ['100.128.0.1', true];
        yield 'test net 1' => ['192.0.2.55', false];
        yield 'test net 2' => ['198.51.100.9', false];
        yield 'test net 3' => ['203.0.113.200', false];
        yield 'benchmarking' => ['198.18.5.5', false];
        yield 'benchmarking upper half' => ['198.19.255.1', false];
        yield 'just outside benchmarking' => ['198.20.0.1', true];
        yield 'relay anycast' => ['192.88.99.1', false];
        yield 'multicast' => ['230.1.1.1', false];
        yield 'protocol assignments' => ['192.0.0.5', false];
        yield 'globally reachable protocol address 9' => ['192.0.0.9', true];
        yield 'globally reachable protocol address 10' => ['192.0.0.10', true];
        yield 'reserved' => ['240.0.0.1', false];
    }

    #[DataProvider('addresses')]
    public function test_it_tells_public_addresses_from_non_global_ranges(string $ip, bool $public): void
    {
        self::assertSame($public, (new Ipv4Parser())->isPublic($ip));
        self::assertSame($public ? $ip : null, (new Ipv4Parser())->parsePublic($ip));
    }

    public function test_a_private_address_in_a_page_is_not_a_public_address(): void
    {
        self::assertNull((new Ipv4Parser())->parsePublic('<p>192.168.0.9</p>'));
        self::assertSame('8.8.8.8', (new Ipv4Parser())->parsePublic('<p>8.8.8.8</p>'));
        self::assertNull((new Ipv4Parser())->parsePublic('nothing here'));
    }
}

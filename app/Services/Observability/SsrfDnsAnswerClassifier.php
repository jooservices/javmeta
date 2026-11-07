<?php

declare(strict_types=1);

namespace App\Services\Observability;

/**
 * Classifies DNS answers the same way the CrawlerX browser SSRF guard does:
 * drop loopback/private/special-use decoys; require at least one public pin.
 */
final class SsrfDnsAnswerClassifier
{
    /**
     * @param  list<string>  $addresses
     * @return array{
     *     public: list<string>,
     *     blocked: list<string>,
     *     safe: bool,
     *     reason: ?string
     * }
     */
    public function classify(array $addresses): array
    {
        $public = [];
        $blocked = [];
        foreach ($addresses as $address) {
            if ($address === '') {
                continue;
            }
            if ($this->isBlocked($address)) {
                $blocked[] = $address;
                continue;
            }
            $public[] = $address;
        }

        if ($public === []) {
            return [
                'public' => [],
                'blocked' => $blocked,
                'safe' => false,
                'reason' => $blocked === [] ? 'dns_failed' : 'no_public_address',
            ];
        }

        return [
            'public' => $public,
            'blocked' => $blocked,
            'safe' => true,
            'reason' => null,
        ];
    }

    public function isBlocked(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! $this->isPublicIpv4($address);
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return ! $this->isPublicIpv6($address);
        }

        return true;
    }

    private function isPublicIpv4(string $address): bool
    {
        $long = ip2long($address);
        if ($long === false) {
            return false;
        }
        $unsigned = sprintf('%u', $long);

        return match (true) {
            $this->inCidr($unsigned, '0.0.0.0', 8) => false,
            $this->inCidr($unsigned, '10.0.0.0', 8) => false,
            $this->inCidr($unsigned, '100.64.0.0', 10) => false,
            $this->inCidr($unsigned, '127.0.0.0', 8) => false,
            $this->inCidr($unsigned, '169.254.0.0', 16) => false,
            $this->inCidr($unsigned, '172.16.0.0', 12) => false,
            $this->inCidr($unsigned, '192.0.0.0', 24) => false,
            $this->inCidr($unsigned, '192.0.2.0', 24) => false,
            $this->inCidr($unsigned, '192.88.99.0', 24) => false,
            $this->inCidr($unsigned, '192.168.0.0', 16) => false,
            $this->inCidr($unsigned, '198.18.0.0', 15) => false,
            $this->inCidr($unsigned, '198.51.100.0', 24) => false,
            $this->inCidr($unsigned, '203.0.113.0', 24) => false,
            $this->inCidr($unsigned, '224.0.0.0', 4) => false,
            $this->inCidr($unsigned, '240.0.0.0', 4) => false,
            default => true,
        };
    }

    private function isPublicIpv6(string $address): bool
    {
        $binary = inet_pton($address);
        if ($binary === false) {
            return false;
        }

        // IPv4-mapped IPv6: ::ffff:0:0/96
        if (str_starts_with($binary, hex2bin('00000000000000000000ffff') ?: '')) {
            $v4 = inet_ntop(substr($binary, 12));

            return is_string($v4) && $this->isPublicIpv4($v4);
        }

        $hextets = unpack('n8', $binary);
        if (! is_array($hextets)) {
            return false;
        }
        /** @var array<int, int> $hextets */
        $first = $hextets[1];

        return match (true) {
            $address === '::' || $address === '::1' => false,
            ($first & 0xfe00) === 0xfc00 => false, // fc00::/7
            ($first & 0xffc0) === 0xfe80 => false, // fe80::/10
            ($first & 0xff00) === 0xff00 => false, // ff00::/8
            $first === 0x2001 && $hextets[2] === 0x0db8 => false, // 2001:db8::/32
            $first === 0x2001 && ($hextets[2] & 0xff00) === 0x0000 => false, // 2001::/23 (teredo etc.)
            $first === 0x2002 => false, // 2002::/16 6to4
            $first === 0x0064 && $hextets[2] === 0xff9b => false, // 64:ff9b::/96 and 64:ff9b:1::/48
            $first === 0x0100 => false, // 100::/64
            default => true,
        };
    }

    private function inCidr(string $unsignedAddress, string $network, int $prefix): bool
    {
        $networkLong = ip2long($network);
        if ($networkLong === false) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : (~((1 << (32 - $prefix)) - 1) & 0xFFFFFFFF);
        $addressInt = (int) $unsignedAddress;
        $networkInt = (int) sprintf('%u', $networkLong);

        return ($addressInt & $mask) === ($networkInt & $mask);
    }
}

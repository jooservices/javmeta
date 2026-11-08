<?php

declare(strict_types=1);

namespace App\Services\Observability;

/** Reads and classifies the public IPv4 address a probe endpoint answers with. */
final class Ipv4Parser
{
    // PHP's reserved-range filter omits some non-global ranges such as TEST-NET and multicast.
    private const NON_GLOBAL_IPV4_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
    ];

    public function parse(string $body): ?string
    {
        $visibleContent = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', ' ', $body) ?? $body;
        $candidate = trim(html_entity_decode(
            strip_tags($visibleContent),
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
        ));
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $candidate;
        }

        return null;
    }

    public function parsePublic(string $body): ?string
    {
        $ip = $this->parse($body);
        if ($ip === null || ! $this->isPublic($ip)) {
            return null;
        }

        return $ip;
    }

    public function isPublic(string $ip): bool
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        if (in_array($ip, ['192.0.0.9', '192.0.0.10'], true)) {
            return true;
        }

        foreach (self::NON_GLOBAL_IPV4_RANGES as $range) {
            if ($this->isInIpv4Range($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private function isInIpv4Range(string $ip, string $range): bool
    {
        [$network, $prefixLength] = explode('/', $range, 2);
        $addressBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $fullBytes = intdiv($prefixLength, 8);
        if (substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}

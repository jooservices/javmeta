<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Closure;
use Throwable;

/**
 * Detects DNS answers that leave the browser SSRF guard with no public pin
 * (ISP sinkhole or decoy-only answers on the Playwright host).
 */
final class BrowserDnsHealthProbe
{
    /** @var Closure(string): list<string> */
    private readonly Closure $lookup;

    /**
     * @param  Closure(string): list<string>|null  $lookup
     */
    public function __construct(
        private readonly SsrfDnsAnswerClassifier $classifier = new SsrfDnsAnswerClassifier(),
        ?Closure $lookup = null,
    ) {
        $this->lookup = $lookup ?? $this->defaultLookup(...);
    }

    /**
     * @param  list<string>  $hosts
     * @return array{
     *     ok: bool,
     *     hosts: list<array{
     *         host: string,
     *         addresses: list<string>,
     *         public: list<string>,
     *         blocked: list<string>,
     *         safe: bool,
     *         reason: ?string
     *     }>
     * }
     */
    public function check(array $hosts): array
    {
        $results = [];
        $ok = true;
        foreach ($hosts as $host) {
            if ($host === '') {
                continue;
            }
            try {
                $addresses = ($this->lookup)($host);
            } catch (Throwable) {
                $addresses = [];
            }
            $classified = $this->classifier->classify($addresses);
            $row = [
                'host' => $host,
                'addresses' => $addresses,
                'public' => $classified['public'],
                'blocked' => $classified['blocked'],
                'safe' => $classified['safe'],
                'reason' => $classified['reason'],
            ];
            $results[] = $row;
            $ok = $ok && $classified['safe'];
        }

        return ['ok' => $ok, 'hosts' => $results];
    }

    /** @return list<string> */
    private function defaultLookup(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false) {
            return [];
        }
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}

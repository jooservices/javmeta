<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Observability\ObservabilityEmitter;
use App\Support\NodeIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use JOOservices\Client\Client\ClientBuilder;
use Nyholm\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Learns each crawler node and configured browser host's public IPv4 without touching crawl requests. */
final class NodeEgressProbe
{
    private const NODE_INDEX_KEY = 'javmeta:egress:nodes';

    public function __construct(
        private readonly ClientBuilder $clientBuilder,
        private readonly Ipv4Parser $ipv4 = new Ipv4Parser(),
    ) {}

    /** @return array{node: string, egress_ip: ?string, egress_kind: string} */
    public function contextForLane(?string $lane = null, ?string $method = null): array
    {
        $browserLane = $lane === 'browser';
        $browserHost = $browserLane ? $this->browserHostForMethod($method) : null;
        $ip = $browserLane ? $this->currentBrowserIp($browserHost) : $this->currentNodeIp();
        if (! (bool) config('javmeta_observability.record_egress_ip', true)) {
            $ip = null;
        }

        return [
            'node' => $this->nodeId(),
            'egress_ip' => $ip,
            'egress_kind' => $browserLane ? 'browser_host' : 'node',
        ];
    }

    public function currentNodeIp(): ?string
    {
        return $this->cachedIp($this->nodeCacheKey($this->nodeId()));
    }

    /** @return array{node: string, egress_ip: ?string} */
    public function context(): array
    {
        $context = $this->contextForLane('direct');

        return ['node' => $context['node'], 'egress_ip' => $context['egress_ip']];
    }

    public function currentBrowserIp(?string $host = null): ?string
    {
        foreach (array_keys($this->browserTargets()) as $targetHost) {
            if ($host !== null && $host !== $targetHost) {
                continue;
            }

            $ip = $this->cachedIp($this->browserCacheKey($targetHost));
            if ($host !== null || $ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    /** @return list<array{node: string, ip: ?string, probe_ok: bool, last_probe_error: ?string, last_change_at: ?string, change_count: int}> */
    public function nodeStatuses(): array
    {
        $nodes = $this->cachedNodeIds();
        $node = $this->nodeId();
        $legacyHostname = NodeIdentity::hostname();
        if ($legacyHostname !== $node && ! $this->hasLiveNodeProbe($legacyHostname)) {
            $nodes = array_values(array_filter(
                $nodes,
                static fn(string $cachedNode): bool => $cachedNode !== $legacyHostname,
            ));
        }
        $nodes[] = $node;
        $nodes = array_values(array_unique($nodes));
        sort($nodes);
        $statuses = [];
        foreach ($nodes as $node) {
            $statuses[] = $this->status($node, $this->nodeCacheKey($node), $this->nodeMetaKey($node));
        }

        return $statuses;
    }

    /** @return list<array{host: string, ip: ?string, probe_ok: bool, last_probe_error: ?string, last_change_at: ?string, change_count: int}> */
    public function browserStatuses(): array
    {
        $statuses = [];
        foreach (array_keys($this->browserTargets()) as $host) {
            $status = $this->status($host, $this->browserCacheKey($host), $this->browserMetaKey($host));
            $statuses[] = [
                'host' => $host,
                'ip' => $status['ip'],
                'probe_ok' => $status['probe_ok'],
                'last_probe_error' => $status['last_probe_error'],
                'last_change_at' => $status['last_change_at'],
                'change_count' => $status['change_count'],
            ];
        }

        return $statuses;
    }

    public function probeNodeIfDue(): void
    {
        $node = $this->nodeId();
        $interval = $this->interval('egress_probe_interval_seconds', 120);
        $lock = null;
        $locked = false;
        try {
            $lock = Cache::lock('javmeta:egress:lock:' . $node, 20);
            $locked = $lock->get();
            if (! $locked) {
                return;
            }

            $previous = Cache::get($this->nodeCacheKey($node), []);
            if (is_array($previous) && ! $this->isDue($previous['last_probe_at'] ?? null, $interval)) {
                return;
            }

            $this->recordNodeProbe($node, $interval);
        } catch (Throwable) {
            // A missing cache backend must not affect worker availability.
        } finally {
            if ($locked && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // Cache lock cleanup is best effort.
                }
            }
        }
    }

    public function probeBrowserHostsIfDue(): void
    {
        $interval = $this->interval('browser_egress_probe_interval_seconds', 900);
        foreach ($this->browserTargets() as $host => $target) {
            $this->probeBrowserHostIfDue($host, $target, $interval);
        }
    }

    /** @param array{kind: string, url: string} $target */
    private function probeBrowserHostIfDue(string $host, array $target, int $interval): void
    {
        $lock = null;
        $locked = false;
        try {
            $lock = Cache::lock('javmeta:browser-egress:lock:' . $host, 20);
            $locked = $lock->get();
            if (! $locked) {
                return;
            }

            $previous = Cache::get($this->browserCacheKey($host), []);
            if (is_array($previous) && ! $this->isDue($previous['last_probe_at'] ?? null, $interval)) {
                return;
            }

            $result = $this->lookupBrowserIp($target);
            $this->storeResult('browser', $host, $result['ip'], $interval, $result['error']);
        } catch (Throwable) {
            // Browser services are optional and never gate crawling.
        } finally {
            if ($locked && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // Cache lock cleanup is best effort.
                }
            }
        }
    }

    private function recordNodeProbe(string $node, int $interval): void
    {
        $ip = $this->lookupNodeIp();
        $this->storeResult('node', $node, $ip, $interval);
        $this->rememberNode($node);
    }

    private function storeResult(string $kind, string $identity, ?string $ip, int $interval, ?string $probeError = null): void
    {
        $cacheKey = $kind === 'node' ? $this->nodeCacheKey($identity) : $this->browserCacheKey($identity);
        $metaKey = $kind === 'node' ? $this->nodeMetaKey($identity) : $this->browserMetaKey($identity);
        $meta = Cache::get($metaKey, []);
        $meta = is_array($meta) ? $meta : [];
        $oldIp = is_string($meta['last_known_ip'] ?? null) ? $meta['last_known_ip'] : null;
        $changed = $ip !== null && $oldIp !== $ip;
        $changeCount = max(0, (int) ($meta['change_count'] ?? 0));
        $lastChangeAt = is_string($meta['last_change_at'] ?? null) ? $meta['last_change_at'] : null;
        if ($changed) {
            $changeCount++;
            $lastChangeAt = CarbonImmutable::now()->toIso8601String();
            $meta['last_known_ip'] = $ip;
            $meta['last_change_at'] = $lastChangeAt;
            $meta['change_count'] = $changeCount;
        }
        $probedAt = CarbonImmutable::now()->toIso8601String();
        Cache::put($cacheKey, [
            'ip' => $ip,
            'probe_ok' => $ip !== null,
            'probe_error' => $probeError,
            'last_probe_at' => $probedAt,
        ], max(1, $interval * 2));
        $storedMeta = [
            'last_known_ip' => $ip !== null ? $ip : $oldIp,
            'last_change_at' => $lastChangeAt,
            'change_count' => $changeCount,
        ];
        if ($kind === 'node') {
            $storedMeta['configured_node'] = $identity;
        }
        Cache::forever($metaKey, $storedMeta);

        if ($changed) {
            $this->publishChange($kind, $identity, $oldIp, $ip, $lastChangeAt);
        }
    }

    private function publishChange(string $kind, string $identity, ?string $oldIp, ?string $newIp, ?string $changedAt): void
    {
        try {
            app(ObservabilityEmitter::class)->emitEgressChange(
                node: $this->nodeId(),
                oldIp: $oldIp,
                newIp: $newIp,
                changedAt: $changedAt,
                host: $kind === 'browser' ? $identity : null,
                egressKind: $kind === 'browser' ? 'browser_host' : 'node',
            );
        } catch (Throwable) {
            // A telemetry outage must not roll back a successful cache update.
        }
    }

    private function lookupNodeIp(): ?string
    {
        $urls = config('javmeta_observability.egress_probe_urls', []);
        if (! is_array($urls)) {
            return null;
        }

        $timeout = $this->interval('egress_probe_timeout_seconds', 5);
        $deadline = hrtime(true) + ($timeout * 1_000_000_000);
        foreach ($urls as $url) {
            if (! is_string($url) || ! $this->isHttpUrl($url)) {
                continue;
            }

            $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
            if ($remaining < 0.05) {
                break;
            }

            $ip = $this->requestIp($url, $remaining);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    /** @param array{kind: string, url: string} $target */
    /** @return array{ip: ?string, error: ?string} */
    private function lookupBrowserIp(array $target): array
    {
        $urls = config('javmeta_observability.egress_probe_urls', []);
        if (! is_array($urls)) {
            return ['ip' => null, 'error' => 'No valid egress probe URL is configured.'];
        }

        $timeout = $this->interval('egress_probe_timeout_seconds', 5);
        $deadline = hrtime(true) + ($timeout * 1_000_000_000);
        $attempted = false;
        $lastError = null;
        foreach ($urls as $url) {
            if (! is_string($url) || ! $this->isHttpUrl($url)) {
                continue;
            }

            $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
            if ($remaining < 0.05) {
                $lastError = 'Probe time limit reached before an echo URL succeeded.';
                break;
            }

            $attempted = true;
            $result = $target['kind'] === 'playwright'
                ? $this->requestPlaywrightIp($target['url'], $url, $remaining)
                : $this->requestFlareSolverrIp($target['url'], $url, $remaining);
            if ($result['ip'] !== null) {
                return $result;
            }

            $lastError = $result['error'];
        }

        return [
            'ip' => null,
            'error' => $attempted
                ? ($lastError ?? 'No public IPv4 address was returned.')
                : 'No valid egress probe URL is configured.',
        ];
    }

    private function browserHostForMethod(?string $method): ?string
    {
        $method = strtolower((string) $method);
        $service = str_contains($method, 'flare')
            ? 'flaresolverr'
            : (str_contains($method, 'playwright') || str_contains($method, 'chrome') ? 'playwright' : null);
        if ($service === null) {
            return null;
        }

        $endpoint = config('javmeta_browser.' . $service . '_url');
        if (! is_string($endpoint) || ! $this->isHttpUrl($endpoint)) {
            return null;
        }

        $parts = parse_url($endpoint);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host !== '' ? $host : null;
    }

    private function requestIp(string $url, float $remainingSeconds): ?string
    {
        try {
            $client = $this->clientBuilder
                ->withTimeout($remainingSeconds)
                ->withConnectTimeout($remainingSeconds)
                ->withProxy('')
                ->withRedirects(false)
                ->build();
            $response = $client->sendRequest(new Request('GET', $url, ['Accept' => 'text/plain']));

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
                ? $this->ipv4->parse($response->getBody()->read(128))
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{ip: ?string, error: ?string} */
    private function requestPlaywrightIp(string $endpoint, string $url, float $timeout): array
    {
        $timeoutMilliseconds = max(1, (int) ceil($timeout * 1000));
        $payload = [
            'script' => 'playwright',
            'config' => [
                'url' => $url,
                'readyTimeoutMs' => $timeoutMilliseconds,
                'settleAfterReadyMs' => 0,
                'readyMarkers' => [],
                'browser' => 'chromium',
                'headless' => true,
                'navigationTimeoutMs' => $timeoutMilliseconds,
                'viewport' => ['width' => 800, 'height' => 600],
                'locale' => 'en-US',
                'timezoneId' => 'UTC',
                'stealthEnabled' => false,
                'stealthLevel' => 'basic',
                'extraHttpHeaders' => [],
                'storageStatePath' => null,
                'storageState' => null,
                'blockResources' => true,
                'userAgent' => null,
            ],
        ];

        $attempt = $this->browserRequest(rtrim($endpoint, '/') . '/fetch', $payload, $timeout);
        if ($attempt['response'] === null) {
            return ['ip' => null, 'error' => $attempt['error']];
        }

        try {
            $rawBody = (string) $attempt['response']->getBody();
        } catch (Throwable) {
            return ['ip' => null, 'error' => 'Browser service response body could not be read.'];
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            return ['ip' => null, 'error' => 'Browser service returned invalid JSON.'];
        }
        $exitCode = $body['exitCode'] ?? null;
        if (! is_int($exitCode)) {
            return ['ip' => null, 'error' => 'Playwright fetch returned an invalid response.'];
        }
        if ($exitCode !== 0) {
            return ['ip' => null, 'error' => $this->playwrightFailureReason($exitCode, $body['stderr'] ?? null)];
        }

        $stdout = is_string($body['stdout'] ?? null) ? $body['stdout'] : '';
        $result = json_decode($stdout, true);
        if (! is_array($result)) {
            return ['ip' => null, 'error' => 'Playwright fetch returned invalid output.'];
        }
        $html = $result['html'] ?? null;
        if (! is_string($html)) {
            return ['ip' => null, 'error' => 'Playwright fetch returned no page content.'];
        }

        $ip = $this->ipv4->parsePublic($html);

        return [
            'ip' => $ip,
            'error' => $ip === null ? 'Echo page did not return a public IPv4 address.' : null,
        ];
    }

    private function playwrightFailureReason(int $exitCode, mixed $stderr): string
    {
        $details = is_string($stderr) ? trim($stderr) : '';
        if ($details === '') {
            return 'Playwright fetch exited with code ' . $exitCode . '.';
        }

        $details = preg_replace('~https?://\S+~i', '[URL]', $details) ?? $details;
        $details = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $details) ?? $details;
        $details = trim(preg_replace('/\s+/', ' ', $details) ?? $details);

        $details = strtolower($details);
        $reason = match (true) {
            str_contains($details, 'timeout'), str_contains($details, 'timed out') => 'Playwright fetch timed out',
            str_contains($details, 'browser launch failed'), str_contains($details, 'could not launch') => 'Playwright browser could not start',
            str_contains($details, 'ssrf_blocked') => 'Playwright fetch target was blocked',
            str_contains($details, 'err_name_not_resolved'), str_contains($details, 'could not resolve') => 'Playwright could not resolve the echo host',
            str_contains($details, 'err_connection_refused'), str_contains($details, 'connection refused') => 'Playwright could not connect to the echo host',
            default => 'Playwright fetch failed',
        };

        return $reason . ' (exit code ' . $exitCode . ').';
    }

    /** @return array{ip: ?string, error: ?string} */
    private function requestFlareSolverrIp(string $endpoint, string $url, float $timeout): array
    {
        $attempt = $this->browserRequest($endpoint, [
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => (int) ceil($timeout * 1000),
        ], $timeout);
        if ($attempt['response'] === null) {
            return ['ip' => null, 'error' => $attempt['error']];
        }

        try {
            $rawBody = (string) $attempt['response']->getBody();
        } catch (Throwable) {
            return ['ip' => null, 'error' => 'Browser service response body could not be read.'];
        }

        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            return ['ip' => null, 'error' => 'Browser service returned invalid JSON.'];
        }
        if (($body['status'] ?? null) !== 'ok') {
            return ['ip' => null, 'error' => 'FlareSolverr returned a failed response.'];
        }

        $solution = is_array($body['solution'] ?? null) ? $body['solution'] : [];
        $ip = $this->ipv4->parsePublic(is_string($solution['response'] ?? null) ? $solution['response'] : '');

        return [
            'ip' => $ip,
            'error' => $ip === null ? 'Echo page did not return a public IPv4 address.' : null,
        ];
    }

    /** @param array<string, mixed> $payload
     *  @return array{response: ?ResponseInterface, error: ?string}
     */
    private function browserRequest(string $url, array $payload, float $timeout): array
    {
        try {
            $client = $this->clientBuilder
                ->withTimeout($timeout)
                ->withConnectTimeout($timeout)
                ->withProxy('')
                ->withRedirects(false)
                ->build();
            $response = $client->sendRequest(new Request(
                'POST',
                $url,
                ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $exception) {
            return ['response' => null, 'error' => $this->probeExceptionReason($exception)];
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return ['response' => null, 'error' => 'Browser service returned HTTP ' . $response->getStatusCode() . '.'];
        }

        return ['response' => $response, 'error' => null];
    }

    private function probeExceptionReason(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'Browser service request timed out.';
        }
        if (str_contains($message, 'connection refused')) {
            return 'Browser service refused the connection.';
        }
        if (str_contains($message, 'could not resolve') || str_contains($message, 'name or service not known')) {
            return 'Browser service host could not be resolved.';
        }

        return 'Browser service request failed (' . class_basename($exception) . ').';
    }

    private function isHttpUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== '';
    }

    /** @return array<string, array{kind: string, url: string}> */
    private function browserTargets(): array
    {
        $targets = [];
        foreach ([
            'playwright' => config('javmeta_browser.playwright_url'),
            'flaresolverr' => config('javmeta_browser.flaresolverr_url'),
        ] as $kind => $endpoint) {
            if (! is_string($endpoint) || ! $this->isHttpUrl($endpoint)) {
                continue;
            }
            $parts = parse_url($endpoint);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host !== '' && ! isset($targets[$host])) {
                $targets[$host] = ['kind' => $kind, 'url' => $endpoint];
            }
        }

        return $targets;
    }

    /** @return array{node: string, ip: ?string, probe_ok: bool, last_probe_error: ?string, last_change_at: ?string, change_count: int} */
    private function status(string $identity, string $cacheKey, string $metaKey): array
    {
        try {
            $current = Cache::get($cacheKey, []);
            $meta = Cache::get($metaKey, []);
        } catch (Throwable) {
            $current = [];
            $meta = [];
        }
        $current = is_array($current) ? $current : [];
        $meta = is_array($meta) ? $meta : [];

        return [
            'node' => $identity,
            'ip' => is_string($current['ip'] ?? null) ? $current['ip'] : null,
            'probe_ok' => (bool) ($current['probe_ok'] ?? false),
            'last_probe_error' => is_string($current['probe_error'] ?? null) ? $current['probe_error'] : null,
            'last_change_at' => is_string($meta['last_change_at'] ?? null) ? $meta['last_change_at'] : null,
            'change_count' => max(0, (int) ($meta['change_count'] ?? 0)),
        ];
    }

    private function cachedIp(string $key): ?string
    {
        try {
            $value = Cache::get($key, []);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) && is_string($value['ip'] ?? null) ? $value['ip'] : null;
    }

    /** @return list<string> */
    private function cachedNodeIds(): array
    {
        try {
            $nodes = Cache::get(self::NODE_INDEX_KEY, []);
        } catch (Throwable) {
            return [];
        }

        return is_array($nodes) ? array_values(array_filter($nodes, 'is_string')) : [];
    }

    private function rememberNode(string $node): void
    {
        $lock = null;
        $locked = false;
        try {
            $lock = Cache::lock('javmeta:egress:nodes-lock', 5);
            $locked = $lock->get();
            if (! $locked) {
                return;
            }
            $nodes = $this->cachedNodeIds();
            $legacyNode = NodeIdentity::hostname();
            if ($legacyNode !== $node && ! $this->hasLiveNodeProbe($legacyNode)) {
                $nodes = array_values(array_filter($nodes, static fn(string $cachedNode): bool => $cachedNode !== $legacyNode));
            }
            $nodes[] = $node;
            $nodes = array_values(array_unique($nodes));
            sort($nodes);
            Cache::forever(self::NODE_INDEX_KEY, $nodes);
        } catch (Throwable) {
            // Node discovery is best effort; the local node remains visible in status output.
        } finally {
            if ($locked && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // Cache lock cleanup is best effort.
                }
            }
        }
    }

    private function hasLiveNodeProbe(string $node): bool
    {
        try {
            $probe = Cache::get($this->nodeCacheKey($node), []);
        } catch (Throwable) {
            // Keep the row when cache state is unavailable; pruning could hide a live node.
            return true;
        }

        return is_array($probe)
            && is_string($probe['last_probe_at'] ?? null);
    }

    private function isDue(mixed $lastProbeAt, int $interval): bool
    {
        if (! is_string($lastProbeAt)) {
            return true;
        }

        try {
            return CarbonImmutable::parse($lastProbeAt)->addSeconds($interval)->lte(CarbonImmutable::now());
        } catch (Throwable) {
            return true;
        }
    }

    private function interval(string $key, int $default): int
    {
        return max(1, (int) config('javmeta_observability.' . $key, $default));
    }

    private function nodeId(): string
    {
        return NodeIdentity::current();
    }

    private function nodeCacheKey(string $node): string
    {
        return 'javmeta:egress:' . $node;
    }

    private function nodeMetaKey(string $node): string
    {
        return 'javmeta:egress-meta:' . $node;
    }

    private function browserCacheKey(string $host): string
    {
        return 'javmeta:browser-egress:' . $host;
    }

    private function browserMetaKey(string $host): string
    {
        return 'javmeta:browser-egress-meta:' . $host;
    }
}

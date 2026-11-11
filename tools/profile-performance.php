<?php

declare(strict_types=1);

const STAGING_HOST = 'jav.soulev.com';
const SAMPLES_PER_CASE = 30;
const TARGET_P95_MS = 400.0;
const REQUEST_TIMEOUT_SECONDS = 15;
const HEALTH_BODY_LIMIT = 65536;

const ROUTES = [
    'search' => [
        'path' => '/api/v1/search?q=beautiful%20office%20drama%202025&per_page=24',
        'cache_ttl_seconds' => 60,
    ],
    'status' => [
        'path' => '/api/v1/status/summary',
        'cache_ttl_seconds' => 15,
    ],
    'movie_facets' => [
        'path' => '/api/v1/movies/facets',
        'cache_ttl_seconds' => 60,
    ],
    'performer_facets' => [
        'path' => '/api/v1/performers/facets',
        'cache_ttl_seconds' => 60,
    ],
];

/** @return array<string, string|bool> */
function parseOptions(array $arguments): array
{
    $valueOptions = [
        'base-url',
        'host-load-average',
        'db-cpu-percent',
        'db-memory-percent',
        'load-captured-at',
        'load-source',
    ];
    $flagOptions = ['authorized-staging', 'confirm-quiet-load', 'confirm-cold-cache'];
    $options = [];

    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if ($argument === '--help' || $argument === '-h') {
            printUsage();
            exit(0);
        }
        if (! str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('Unexpected positional argument.');
        }

        [$name, $inlineValue] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
        if (isset($options[$name])) {
            throw new InvalidArgumentException('An option was supplied more than once.');
        }
        if (in_array($name, $flagOptions, true)) {
            if ($inlineValue !== null) {
                throw new InvalidArgumentException('Confirmation options do not accept values.');
            }
            $options[$name] = true;
            continue;
        }
        if (! in_array($name, $valueOptions, true)) {
            throw new InvalidArgumentException('Unknown option.');
        }

        $value = $inlineValue;
        if ($value === null) {
            $index++;
            $value = $arguments[$index] ?? null;
        }
        if (! is_string($value) || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException('A required option value is missing.');
        }
        $options[$name] = $value;
    }

    return $options;
}

function printUsage(): void
{
    echo <<<'USAGE'
Measure JavMeta staging HTTP response times. Requests are GET-only and target jav.soulev.com.
The API key is read from JAVMETA_STAGING_API_KEY and is never printed.

Required options:
  --base-url https://jav.soulev.com
  --host-load-average 1m,5m,15m
  --db-cpu-percent NUMBER
  --db-memory-percent NUMBER
  --load-captured-at RFC3339_TIMESTAMP
  --load-source openobserve|periodic-report|other-approved
  --authorized-staging --confirm-quiet-load --confirm-cold-cache

The confirmation flags are operator attestations. They do not grant authorization.
The cold-cache attestation must include disabled perf:warm jobs and no concurrent
requests for the measured cache keys. Each cold batch starts after the endpoint TTL
and contains one first-after-expiry request plus 29 sequential follow-ups.

USAGE;
}

function validateBaseUrl(string $baseUrl): string
{
    $parts = parse_url($baseUrl);
    if (! is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== STAGING_HOST
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || ! in_array($parts['path'] ?? '', ['', '/'], true)
        || ! in_array($parts['port'] ?? 443, [443], true)) {
        throw new InvalidArgumentException('The target must be the HTTPS staging origin https://' . STAGING_HOST . '.');
    }

    return 'https://' . STAGING_HOST;
}

/** @return list<float> */
function parseLoadAverage(string $raw): array
{
    $values = explode(',', $raw);
    if (count($values) !== 3) {
        throw new InvalidArgumentException('Host load must contain the 1, 5, and 15 minute averages.');
    }

    $load = [];
    foreach ($values as $value) {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new InvalidArgumentException('Host load values must be finite non-negative numbers.');
        }
        $load[] = (float) $value;
    }

    return $load;
}

function parsePercent(string $raw, string $option, bool $allowOverOneHundred = false): float
{
    if (! is_numeric($raw) || ! is_finite((float) $raw) || (float) $raw < 0 || (! $allowOverOneHundred && (float) $raw > 100)) {
        throw new InvalidArgumentException($option . ' must be a finite non-negative number' . ($allowOverOneHundred ? '.' : ' between 0 and 100.'));
    }

    return (float) $raw;
}

/** @return array<string, mixed> */
function validateConfiguration(array $options): array
{
    foreach (['base-url', 'host-load-average', 'db-cpu-percent', 'db-memory-percent', 'load-captured-at', 'load-source'] as $required) {
        if (! isset($options[$required]) || ! is_string($options[$required])) {
            throw new InvalidArgumentException('Missing required option --' . $required . '.');
        }
    }
    foreach (['authorized-staging', 'confirm-quiet-load', 'confirm-cold-cache'] as $required) {
        if (($options[$required] ?? false) !== true) {
            throw new InvalidArgumentException('Missing required confirmation --' . $required . '.');
        }
    }

    $apiKey = getenv('JAVMETA_STAGING_API_KEY');
    if (! is_string($apiKey) || trim($apiKey) === '' || strlen($apiKey) > 512 || preg_match('/[\x00-\x1F\x7F]/', $apiKey) !== 0) {
        throw new InvalidArgumentException('JAVMETA_STAGING_API_KEY must be supplied through the approved secret source.');
    }

    $loadCapturedAt = $options['load-captured-at'];
    if (! is_string($loadCapturedAt) || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $loadCapturedAt) !== 1) {
        throw new InvalidArgumentException('--load-captured-at must be an RFC3339 timestamp with a timezone.');
    }
    try {
        $loadTime = new DateTimeImmutable($loadCapturedAt);
    } catch (Throwable) {
        throw new InvalidArgumentException('--load-captured-at is not a valid timestamp.');
    }

    $source = $options['load-source'];
    if (! in_array($source, ['openobserve', 'periodic-report', 'other-approved'], true)) {
        throw new InvalidArgumentException('--load-source must be openobserve, periodic-report, or other-approved.');
    }

    return [
        'base_url' => validateBaseUrl((string) $options['base-url']),
        'api_key' => $apiKey,
        'load_snapshot' => [
            'source' => $source,
            'captured_at' => $loadTime->format(DateTimeInterface::ATOM),
            'host_load_average_1m_5m_15m' => parseLoadAverage((string) $options['host-load-average']),
            'database_cpu_percent' => parsePercent((string) $options['db-cpu-percent'], '--db-cpu-percent', true),
            'database_memory_percent' => parsePercent((string) $options['db-memory-percent'], '--db-memory-percent'),
            'operator_confirmed_quiet' => true,
        ],
    ];
}

/** @return list<string> */
function resolvePublicStagingAddresses(): array
{
    $records = dns_get_record(STAGING_HOST, DNS_A | DNS_AAAA);
    if (! is_array($records)) {
        throw new RuntimeException('Staging DNS lookup failed; no HTTP request was sent.');
    }
    $addresses = [];
    foreach ($records as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if (! is_string($address)) {
            continue;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Staging DNS returned a private or reserved address; no HTTP request was sent.');
        }
        $addresses[] = str_contains($address, ':') ? '[' . $address . ']' : $address;
    }

    if ($addresses === []) {
        throw new RuntimeException('Staging DNS did not return a public address; no HTTP request was sent.');
    }

    return array_values(array_unique($addresses));
}

/** @param list<string> $addresses */
function createHttpClient(array $addresses): CurlHandle
{
    $handle = curl_init();
    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Could not initialize the HTTP client.');
    }
    $resolve = STAGING_HOST . ':443:' . implode(',', $addresses);
    $configured = curl_setopt_array($handle, [
        CURLOPT_RESOLVE => [$resolve],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS_STR => 'https',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
        CURLOPT_USERAGENT => 'JavMetaPerformanceBaseline/1.0 (JOOXCR-270)',
        CURLOPT_ENCODING => '',
    ]);
    if (! $configured) {
        curl_close($handle);
        throw new RuntimeException('Could not configure the HTTP client.');
    }

    return $handle;
}

/** @return array{ok: bool, status: int, elapsed_ms: float, error: ?string, body: string} */
function get(string $url, CurlHandle $handle, ?string $apiKey = null, bool $captureBody = false): array
{
    $body = '';
    $bodyLimitExceeded = false;
    $configured = curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $apiKey === null
            ? ['Accept: application/json']
            : ['Accept: application/json', 'X-API-Key: ' . $apiKey],
        CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body, &$bodyLimitExceeded, $captureBody): int {
            if ($captureBody) {
                if (strlen($body) + strlen($chunk) > HEALTH_BODY_LIMIT) {
                    $bodyLimitExceeded = true;

                    return 0;
                }
                $body .= $chunk;
            }

            return strlen($chunk);
        },
    ]);
    if (! $configured) {
        throw new RuntimeException('Could not configure an HTTP request.');
    }

    $startedAt = hrtime(true);
    $response = curl_exec($handle);
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_errno($handle);
    if ($response === false || $bodyLimitExceeded || $curlError !== 0) {
        $error = $bodyLimitExceeded ? 'health_body_too_large' : ($curlError === 28 ? 'timeout' : 'transport_' . $curlError);

        return ['ok' => false, 'status' => $status, 'elapsed_ms' => $elapsedMs, 'error' => $error, 'body' => ''];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'status' => $status, 'elapsed_ms' => $elapsedMs, 'error' => 'http_' . $status, 'body' => ''];
    }

    return ['ok' => true, 'status' => $status, 'elapsed_ms' => $elapsedMs, 'error' => null, 'body' => $body];
}

/** @param list<float> $values */
function percentile(array $values, float $percentile): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $index = max(0, (int) ceil(count($values) * $percentile) - 1);

    return round($values[$index], 3);
}

/** @return array<string, mixed> */
function measureBatch(string $url, CurlHandle $handle, bool $afterExpiry = false, ?string $apiKey = null): array
{
    $times = [];
    $errors = [];
    $calls = 0;
    $firstAfterExpiry = null;
    for ($sample = 0; $sample < SAMPLES_PER_CASE; $sample++) {
        $result = get($url, $handle, $apiKey);
        $calls++;
        if (! $result['ok']) {
            $errors[$result['error']] = ($errors[$result['error']] ?? 0) + 1;
            break;
        }
        $times[] = $result['elapsed_ms'];
        if ($afterExpiry && $sample === 0) {
            $firstAfterExpiry = $result['elapsed_ms'];
        }
    }

    $p50 = percentile($times, 0.50);
    $p95 = percentile($times, 0.95);
    $complete = count($times) === SAMPLES_PER_CASE && $errors === [];
    $targetOutcome = ! $complete || $p95 === null ? 'invalid' : ($p95 < TARGET_P95_MS ? 'met' : 'not_met');

    $batch = [
        'requested_samples' => SAMPLES_PER_CASE,
        'observed_requests' => $calls,
        'successful_samples' => count($times),
        'errors' => $errors,
        'p50_ms' => $p50,
        'p95_ms' => $p95,
        'p95_target_outcome' => $targetOutcome,
    ];
    if ($firstAfterExpiry !== null) {
        $batch['first_after_expiry_ms'] = round($firstAfterExpiry, 3);
        $batch['first_after_expiry_target_outcome'] = $firstAfterExpiry < TARGET_P95_MS ? 'under_400_ms' : 'at_or_over_400_ms';
    }

    return $batch;
}

function waitForExpiry(int $ttlSeconds, int $primedAt): void
{
    $waitUntil = $primedAt + (($ttlSeconds + 1) * 1_000_000_000);
    while (($remaining = $waitUntil - hrtime(true)) > 0) {
        $seconds = intdiv($remaining, 1_000_000_000);
        $nanoseconds = $remaining % 1_000_000_000;
        $sleepResult = time_nanosleep($seconds, $nanoseconds);
        if ($sleepResult === false) {
            continue;
        }
    }
}

/** @return array<string, mixed> */
function notRunBatch(string $reason): array
{
    return [
        'requested_samples' => SAMPLES_PER_CASE,
        'observed_requests' => 0,
        'successful_samples' => 0,
        'errors' => [],
        'p50_ms' => null,
        'p95_ms' => null,
        'p95_target_outcome' => 'invalid',
        'reason' => $reason,
    ];
}

function outputReport(array $report, int $exitCode): never
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($exitCode);
}

function routeRecord(array $route, array $load, array $warm, array $cold): array
{
    return [
        'path' => parse_url($route['path'], PHP_URL_PATH),
        'cache_ttl_seconds' => $route['cache_ttl_seconds'],
        'load_snapshot' => $load,
        'warm' => $warm,
        'cold_after_expiry' => $cold,
    ];
}

function emptyRouteRecords(array $load, string $reason): array
{
    $routes = [];
    foreach (ROUTES as $name => $route) {
        $routes[$name] = routeRecord($route, $load, notRunBatch($reason), notRunBatch($reason));
    }

    return $routes;
}

function methodSummary(): array
{
    return [
        'http_method' => 'GET',
        'sequential' => true,
        'samples_per_route_per_case' => SAMPLES_PER_CASE,
        'p50_p95_method' => 'nearest rank',
        'request_timeout_seconds' => REQUEST_TIMEOUT_SECONDS,
        'cold_cache_attestation' => 'operator confirmed perf:warm is disabled and no concurrent request repopulates these cache keys',
        'cold_batch_note' => 'first_after_expiry_ms is the first request after TTL; the remaining 29 calls are sequential follow-ups and may be cache hits',
        'response_body' => 'discarded for measured routes; health body is limited to 64 KiB and status only is recorded',
    ];
}

try {
    $configuration = validateConfiguration(parseOptions($argv));
    $load = $configuration['load_snapshot'];
    $baseUrl = $configuration['base_url'];
    try {
        $handle = createHttpClient(resolvePublicStagingAddresses());
    } catch (Throwable $error) {
        outputReport([
            'run_at_utc' => gmdate(DATE_ATOM),
            'valid' => false,
            'target' => $baseUrl,
            'method' => methodSummary(),
            'load_snapshot' => $load,
            'health' => ['http_status' => null, 'status' => 'not_checked'],
            'auth_verify_http_status' => null,
            'routes' => emptyRouteRecords($load, 'staging_network_preflight_failed'),
            'target_assessment' => 'not_assessed',
            'invalid_reason' => 'staging_network_preflight_failed',
            'detail' => $error->getMessage(),
        ], 1);
    }
    $health = get($baseUrl . '/api/health', $handle, captureBody: true);
    $healthPayload = $health['ok'] ? json_decode($health['body'], true) : null;
    $healthStatus = is_array($healthPayload) ? ($healthPayload['status'] ?? null) : null;
    $auth = null;
    $routes = [];
    $invalidReason = null;

    if (! $health['ok'] || $health['status'] !== 200 || $healthStatus !== 'ok') {
        $invalidReason = ! $health['ok'] ? $health['error'] : (is_string($healthStatus) ? 'health_' . $healthStatus : 'health_invalid_response');
        $routes = emptyRouteRecords($load, 'staging_health_preflight_failed');
    } else {
        $auth = get($baseUrl . '/api/v1/auth/verify', $handle, $configuration['api_key']);
        if (! $auth['ok'] || $auth['status'] !== 200) {
            $invalidReason = $auth['error'] ?? 'api_key_preflight_failed';
            $routes = emptyRouteRecords($load, 'api_key_preflight_failed');
        }
    }

    $failedRoute = null;
    if ($invalidReason === null) {
        foreach (ROUTES as $name => $route) {
            $url = $baseUrl . $route['path'];
            $primedAt = hrtime(true);
            $prime = get($url, $handle, $configuration['api_key']);
            if (! $prime['ok']) {
                $warm = [
                    'requested_samples' => SAMPLES_PER_CASE,
                    'observed_requests' => 1,
                    'successful_samples' => 0,
                    'errors' => [$prime['error'] => 1],
                    'p50_ms' => null,
                    'p95_ms' => null,
                    'p95_target_outcome' => 'invalid',
                ];
                $routes[$name] = routeRecord($route, $load, $warm, notRunBatch('cache_prime_failed'));
                $failedRoute = $name;
                break;
            }

            $warm = measureBatch($url, $handle, false, $configuration['api_key']);
            if ($warm['p95_target_outcome'] === 'invalid') {
                $routes[$name] = routeRecord($route, $load, $warm, notRunBatch('warm_batch_failed'));
                $failedRoute = $name;
                break;
            }

            waitForExpiry($route['cache_ttl_seconds'], $primedAt);
            $cold = measureBatch($url, $handle, true, $configuration['api_key']);
            $routes[$name] = routeRecord($route, $load, $warm, $cold);
            if ($cold['p95_target_outcome'] === 'invalid') {
                $failedRoute = $name;
                break;
            }
        }
    }

    if ($failedRoute !== null) {
        $invalidReason = 'request_error_on_' . $failedRoute;
        foreach (ROUTES as $name => $route) {
            if (! isset($routes[$name])) {
                $routes[$name] = routeRecord(
                    $route,
                    $load,
                    notRunBatch('stopped_after_' . $failedRoute . '_failure'),
                    notRunBatch('stopped_after_' . $failedRoute . '_failure'),
                );
            }
        }
    }

    curl_close($handle);
    if ($invalidReason !== null) {
        outputReport([
            'run_at_utc' => gmdate(DATE_ATOM),
            'valid' => false,
            'target' => $baseUrl,
            'method' => methodSummary(),
            'load_snapshot' => $load,
            'health' => ['http_status' => $health['status'], 'status' => $healthStatus],
            'auth_verify_http_status' => $auth['status'] ?? null,
            'routes' => $routes,
            'target_assessment' => 'not_assessed',
            'invalid_reason' => $invalidReason,
        ], 1);
    }

    $allMet = true;
    foreach ($routes as $route) {
        if ($route['warm']['p95_target_outcome'] !== 'met' || $route['cold_after_expiry']['p95_target_outcome'] !== 'met') {
            $allMet = false;
        }
    }
    outputReport([
        'run_at_utc' => gmdate(DATE_ATOM),
        'valid' => true,
        'target' => $baseUrl,
        'method' => methodSummary(),
        'load_snapshot' => $load,
        'client_load_average_1m_5m_15m' => sys_getloadavg(),
        'health' => ['http_status' => $health['status'], 'status' => $healthStatus],
        'auth_verify_http_status' => $auth['status'] ?? null,
        'routes' => $routes,
        'target_assessment' => $allMet ? 'met' : 'not_met',
        'target_p95_ms' => '< ' . TARGET_P95_MS,
    ], 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Benchmark not started: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

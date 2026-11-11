<?php

declare(strict_types=1);

const STAGING_HOST = 'jav.soulevil.com';
const SAMPLES_PER_CASE = 30;
const TARGET_P95_MS = 400.0;
const REQUEST_TIMEOUT_SECONDS = 15;
const HEALTH_BODY_LIMIT = 65536;
const LOAD_SNAPSHOT_MAX_AGE_SECONDS = 60;
const CACHE_EXPIRY_SAFETY_SECONDS = 1;

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
Measure JavMeta staging HTTP response times. Requests are GET-only and target jav.soulevil.com.
The API key is read from JAVMETA_STAGING_API_KEY and is never printed.

Required options:
  --base-url https://jav.soulevil.com
  --host-load-average 1m,5m,15m
  --db-cpu-percent NUMBER
  --db-memory-percent NUMBER
  --load-captured-at RFC3339_TIMESTAMP
  --load-source openobserve|periodic-report|other-approved
  --authorized-staging --confirm-quiet-load --confirm-cold-cache

The confirmation flags are operator attestations. They do not grant authorization.
The cold-cache attestation must include disabled perf:warm jobs and no concurrent
requests for the measured cache keys. Every cold sample waits for the endpoint TTL
plus one second after the previous possible cache fill. Warm samples are accepted
only when all 30 complete before the earliest possible expiry after the prime.

After all routes, enter one JSON line with a timestamped ending load snapshot:
{"source":"openobserve","captured_at":"RFC3339_TIMESTAMP",
"host_load_average_1m_5m_15m":"1,1,1","database_cpu_percent":10,
"database_memory_percent":20,"operator_confirmed_quiet_for_interval":true}
The start snapshot must be at most 60 seconds old. The ending snapshot must be
captured within 60 seconds after the benchmark and attest quiet load throughout.

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

function parsePercent(mixed $raw, string $option, bool $allowOverOneHundred = false): float
{
    if ((! is_string($raw) && ! is_int($raw) && ! is_float($raw))
        || ! is_numeric($raw)
        || ! is_finite((float) $raw)
        || (float) $raw < 0
        || (! $allowOverOneHundred && (float) $raw > 100)) {
        throw new InvalidArgumentException($option . ' must be a finite non-negative number' . ($allowOverOneHundred ? '.' : ' between 0 and 100.'));
    }

    return (float) $raw;
}

function parseTimestamp(string $timestamp, string $option): DateTimeImmutable
{
    if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $timestamp) !== 1) {
        throw new InvalidArgumentException($option . ' must be an RFC3339 timestamp with a timezone.');
    }

    try {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        throw new InvalidArgumentException($option . ' is not a valid timestamp.');
    }
}

function isSnapshotFreshAt(array $snapshot, DateTimeImmutable $referenceTime): bool
{
    try {
        $capturedAt = parseTimestamp((string) ($snapshot['captured_at'] ?? ''), 'load snapshot');
    } catch (InvalidArgumentException) {
        return false;
    }

    $ageSeconds = $referenceTime->getTimestamp() - $capturedAt->getTimestamp();

    return $ageSeconds >= 0 && $ageSeconds <= LOAD_SNAPSHOT_MAX_AGE_SECONDS;
}

/** @return array<string, mixed> */
function normalizeLoadSnapshot(array $snapshot, bool $requireIntervalAttestation): array
{
    $source = $snapshot['source'] ?? null;
    if (! is_string($source) || ! in_array($source, ['openobserve', 'periodic-report', 'other-approved'], true)) {
        throw new InvalidArgumentException('Load snapshot source is invalid.');
    }
    $capturedAt = $snapshot['captured_at'] ?? null;
    if (! is_string($capturedAt)) {
        throw new InvalidArgumentException('Load snapshot timestamp is missing.');
    }
    $hostLoad = $snapshot['host_load_average_1m_5m_15m'] ?? null;
    if (is_array($hostLoad)) {
        $hostLoad = implode(',', array_map(static fn(mixed $value): string => (string) $value, $hostLoad));
    }
    if (! is_string($hostLoad)) {
        throw new InvalidArgumentException('Host load values are missing.');
    }

    $normalized = [
        'source' => $source,
        'captured_at' => parseTimestamp($capturedAt, 'load snapshot')->format(DateTimeInterface::ATOM),
        'host_load_average_1m_5m_15m' => parseLoadAverage($hostLoad),
        'database_cpu_percent' => parsePercent($snapshot['database_cpu_percent'] ?? null, 'database CPU percent', true),
        'database_memory_percent' => parsePercent($snapshot['database_memory_percent'] ?? null, 'database memory percent'),
    ];

    if ($requireIntervalAttestation) {
        $normalized['operator_confirmed_quiet_for_interval'] = ($snapshot['operator_confirmed_quiet_for_interval'] ?? false) === true;
    } else {
        $normalized['operator_confirmed_quiet_at_start'] = ($snapshot['operator_confirmed_quiet_at_start']
            ?? $snapshot['operator_confirmed_quiet_for_interval']
            ?? false) === true;
    }

    return $normalized;
}

/** @return array<string, mixed> */
function assessLoadEvidence(
    array $startSnapshot,
    array $endSnapshot,
    DateTimeImmutable $runStartedAt,
    DateTimeImmutable $runEndedAt,
    DateTimeImmutable $checkedAt,
): array {
    try {
        $start = normalizeLoadSnapshot($startSnapshot, false);
        $end = normalizeLoadSnapshot($endSnapshot, true);
    } catch (InvalidArgumentException) {
        return ['valid' => false, 'reason' => 'load_snapshot_invalid'];
    }

    $evidence = [
        'source' => $start['source'],
        'benchmark_started_at' => $runStartedAt->format(DateTimeInterface::ATOM),
        'benchmark_completed_at' => $runEndedAt->format(DateTimeInterface::ATOM),
        'start_snapshot' => $start,
        'end_snapshot' => $end,
    ];
    if ($start['source'] !== $end['source']) {
        return array_merge(['valid' => false, 'reason' => 'load_snapshot_source_mismatch'], $evidence);
    }

    $startTime = parseTimestamp($start['captured_at'], 'load start snapshot');
    $startAge = $runStartedAt->getTimestamp() - $startTime->getTimestamp();
    if ($startAge < 0 || $startAge > LOAD_SNAPSHOT_MAX_AGE_SECONDS) {
        return array_merge(['valid' => false, 'reason' => 'load_start_snapshot_stale_or_future'], $evidence);
    }

    $endTime = parseTimestamp($end['captured_at'], 'load end snapshot');
    $endDelay = $endTime->getTimestamp() - $runEndedAt->getTimestamp();
    if ($endDelay < 0
        || $endDelay > LOAD_SNAPSHOT_MAX_AGE_SECONDS
        || $endTime->getTimestamp() > $checkedAt->getTimestamp()) {
        return array_merge(['valid' => false, 'reason' => 'load_end_snapshot_stale_or_future'], $evidence);
    }
    if ($end['operator_confirmed_quiet_for_interval'] !== true) {
        return array_merge(['valid' => false, 'reason' => 'load_interval_not_confirmed_quiet'], $evidence);
    }

    return array_merge([
        'valid' => true,
        'operator_confirmed_quiet_for_interval' => true,
    ], $evidence);
}

/** @return array<string, mixed>|null */
function readFinalLoadSnapshot(): ?array
{
    fwrite(STDERR, "Benchmark routes completed. Paste the ending load snapshot JSON line: ");
    $line = fgets(STDIN);
    if (! is_string($line) || trim($line) === '') {
        return null;
    }

    try {
        $snapshot = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot)) {
            return null;
        }
        return normalizeLoadSnapshot($snapshot, true);
    } catch (Throwable) {
        return null;
    }
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

    $loadTime = parseTimestamp($options['load-captured-at'], '--load-captured-at');

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
            'operator_confirmed_quiet_at_start' => true,
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

/** @param list<float> $times
 *  @param array<string, int> $errors
 *  @param array<string, mixed> $conditionDetails
 *  @return array<string, mixed>
 */
function summarizeBatch(
    array $times,
    int $observedRequests,
    int $successfulSamples,
    array $errors,
    bool $cacheConditionVerified,
    ?string $reason,
    array $conditionDetails = [],
): array {
    $p50 = percentile($times, 0.50);
    $p95 = percentile($times, 0.95);
    $complete = count($times) === SAMPLES_PER_CASE
        && $successfulSamples === SAMPLES_PER_CASE
        && $errors === []
        && $cacheConditionVerified;

    $batch = [
        'requested_samples' => SAMPLES_PER_CASE,
        'observed_requests' => $observedRequests,
        'successful_samples' => $successfulSamples,
        'verified_samples' => count($times),
        'cache_condition_verified' => $cacheConditionVerified,
        'errors' => $errors,
        'p50_ms' => $p50,
        'p95_ms' => $p95,
        'p95_target_outcome' => ! $complete || $p95 === null
            ? 'invalid'
            : ($p95 < TARGET_P95_MS ? 'met' : 'not_met'),
    ];
    if ($reason !== null) {
        $batch['reason'] = $reason;
    }

    return array_merge($batch, $conditionDetails);
}

/** @param callable(): array<string, mixed> $request
 *  @param callable(): int|null $clock
 *  @return array<string, mixed>
 */
function measureWarmSamples(callable $request, int $ttlSeconds, int $primedAt, ?callable $clock = null): array
{
    $clock ??= static fn(): int => hrtime(true);
    $earliestExpiryAt = $primedAt + ($ttlSeconds * 1_000_000_000);
    $times = [];
    $errors = [];
    $observedRequests = 0;
    $successfulSamples = 0;
    $reason = null;

    for ($sample = 0; $sample < SAMPLES_PER_CASE; $sample++) {
        if ($clock() >= $earliestExpiryAt) {
            $reason = $sample === 0 ? 'prime_expired_before_warm_samples' : 'warm_batch_exceeded_ttl';
            break;
        }

        $result = $request();
        $observedRequests++;
        if (! ($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? 'unknown');
            $errors[$error] = ($errors[$error] ?? 0) + 1;
            $reason = 'warm_request_failed';
            break;
        }
        $successfulSamples++;
        if ($clock() >= $earliestExpiryAt) {
            $reason = 'warm_sample_completed_after_expiry';
            break;
        }
        $times[] = (float) $result['elapsed_ms'];
    }

    $verified = count($times) === SAMPLES_PER_CASE && $successfulSamples === SAMPLES_PER_CASE && $errors === [];

    return summarizeBatch(
        $times,
        $observedRequests,
        $successfulSamples,
        $errors,
        $verified,
        $reason,
        ['verified_warm_samples' => count($times)],
    );
}

function waitForExpiry(
    int $ttlSeconds,
    int $lastPossibleFillAt,
    ?callable $clock = null,
    ?callable $sleep = null,
): float {
    $clock ??= static fn(): int => hrtime(true);
    $sleep ??= static function (int $remainingNanoseconds): void {
        time_nanosleep(
            intdiv($remainingNanoseconds, 1_000_000_000),
            $remainingNanoseconds % 1_000_000_000,
        );
    };
    $deadline = $lastPossibleFillAt + (($ttlSeconds + CACHE_EXPIRY_SAFETY_SECONDS) * 1_000_000_000);

    while (($remaining = $deadline - $clock()) > 0) {
        $beforeSleep = $clock();
        $sleep($remaining);
        if ($clock() <= $beforeSleep) {
            break;
        }
    }

    return ($clock() - $lastPossibleFillAt) / 1_000_000_000;
}

/** @param callable(): array<string, mixed> $request
 *  @param callable(): int|null $clock
 *  @param callable(int): void|null $sleep
 *  @return array<string, mixed>
 */
function measureColdSamples(
    callable $request,
    int $ttlSeconds,
    int $lastPossibleFillAt,
    ?callable $clock = null,
    ?callable $sleep = null,
): array {
    $clock ??= static fn(): int => hrtime(true);
    $times = [];
    $errors = [];
    $expiryWaits = [];
    $observedRequests = 0;
    $successfulSamples = 0;
    $reason = null;

    for ($sample = 0; $sample < SAMPLES_PER_CASE; $sample++) {
        $waitedSeconds = waitForExpiry($ttlSeconds, $lastPossibleFillAt, $clock, $sleep);
        if ($waitedSeconds < $ttlSeconds + CACHE_EXPIRY_SAFETY_SECONDS) {
            $reason = 'expiry_wait_incomplete';
            break;
        }
        $expiryWaits[] = round($waitedSeconds, 3);

        $result = $request();
        $observedRequests++;
        if (! ($result['ok'] ?? false)) {
            $error = (string) ($result['error'] ?? 'unknown');
            $errors[$error] = ($errors[$error] ?? 0) + 1;
            $reason = 'cold_request_failed';
            break;
        }
        $successfulSamples++;
        $times[] = (float) $result['elapsed_ms'];
        $lastPossibleFillAt = $clock();
    }

    $verified = count($times) === SAMPLES_PER_CASE
        && count($expiryWaits) === SAMPLES_PER_CASE
        && $successfulSamples === SAMPLES_PER_CASE
        && $errors === [];
    $firstAfterExpiry = $times[0] ?? null;
    $details = [
        'verified_after_expiry_samples' => count($times),
        'expiry_wait_seconds' => $expiryWaits,
    ];
    if ($firstAfterExpiry !== null) {
        $details['first_after_expiry_ms'] = round($firstAfterExpiry, 3);
        $details['first_after_expiry_target_outcome'] = $firstAfterExpiry < TARGET_P95_MS
            ? 'under_400_ms'
            : 'at_or_over_400_ms';
    }

    return summarizeBatch(
        $times,
        $observedRequests,
        $successfulSamples,
        $errors,
        $verified,
        $reason,
        $details,
    );
}

/** @return array<string, mixed> */
function notRunBatch(string $reason): array
{
    return [
        'requested_samples' => SAMPLES_PER_CASE,
        'observed_requests' => 0,
        'successful_samples' => 0,
        'verified_samples' => 0,
        'cache_condition_verified' => false,
        'errors' => [],
        'p50_ms' => null,
        'p95_ms' => null,
        'p95_target_outcome' => 'invalid',
        'reason' => $reason,
        'verified_warm_samples' => 0,
        'verified_after_expiry_samples' => 0,
        'expiry_wait_seconds' => [],
    ];
}

function outputReport(array $report, int $exitCode): never
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($exitCode);
}

function routeRecord(array $route, array $load, array $warm, array $cold, float $cacheSeedWaitSeconds = 0.0): array
{
    return [
        'path' => parse_url($route['path'], PHP_URL_PATH),
        'cache_ttl_seconds' => $route['cache_ttl_seconds'],
        'cache_seed_wait_seconds' => round($cacheSeedWaitSeconds, 3),
        'load_start_snapshot' => $load,
        'warm' => $warm,
        'cold_after_expiry' => $cold,
    ];
}

/** @param array<string, mixed> $routes
 *  @param array<string, mixed> $loadEvidence
 *  @return array<string, mixed>
 */
function attachLoadEvidence(array $routes, array $loadEvidence): array
{
    foreach ($routes as &$route) {
        $route['load_evidence'] = $loadEvidence;
    }

    return $routes;
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
        'cold_batch_note' => 'each of 30 samples waits for TTL plus one second after the previous possible cache fill',
        'prime_note' => 'before priming a route, the tool waits until its pre-run cache key has been expired for at least one second',
        'warm_cache_note' => 'all 30 responses must complete before the earliest expiry measured from the start of the prime request',
        'load_evidence_note' => 'fresh timestamped start and end snapshots plus operator confirmation of quiet load for the complete measurement interval are required',
        'response_body' => 'discarded for measured routes; health body is limited to 64 KiB and status only is recorded',
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

try {
    $configuration = validateConfiguration(parseOptions($argv));
    $load = $configuration['load_snapshot'];
    $baseUrl = $configuration['base_url'];
    if (! isSnapshotFreshAt($load, new DateTimeImmutable('now', new DateTimeZone('UTC')))) {
        outputReport([
            'run_at_utc' => gmdate(DATE_ATOM),
            'valid' => false,
            'target' => $baseUrl,
            'method' => methodSummary(),
            'load_start_snapshot' => $load,
            'health' => ['http_status' => null, 'status' => 'not_checked'],
            'auth_verify_http_status' => null,
            'routes' => emptyRouteRecords($load, 'load_start_snapshot_stale_or_future'),
            'target_assessment' => 'not_assessed',
            'invalid_reason' => 'load_start_snapshot_stale_or_future',
        ], 1);
    }

    try {
        $handle = createHttpClient(resolvePublicStagingAddresses());
    } catch (Throwable $error) {
        outputReport([
            'run_at_utc' => gmdate(DATE_ATOM),
            'valid' => false,
            'target' => $baseUrl,
            'method' => methodSummary(),
            'load_start_snapshot' => $load,
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

    $benchmarkStartedAt = null;
    $benchmarkEndedAt = null;
    $benchmarkStartedMonotonic = null;
    $failedRoute = null;
    if ($invalidReason === null) {
        $benchmarkStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $benchmarkStartedMonotonic = hrtime(true);
        if (! isSnapshotFreshAt($load, $benchmarkStartedAt)) {
            $invalidReason = 'load_start_snapshot_stale_or_future';
            $routes = emptyRouteRecords($load, $invalidReason);
        } else {
            foreach (ROUTES as $name => $route) {
                $url = $baseUrl . $route['path'];
                $cacheSeedWait = waitForExpiry(
                    $route['cache_ttl_seconds'],
                    $benchmarkStartedMonotonic,
                );
                if ($cacheSeedWait < $route['cache_ttl_seconds'] + CACHE_EXPIRY_SAFETY_SECONDS) {
                    $warm = summarizeBatch([], 0, 0, [], false, 'cache_seed_wait_incomplete');
                    $routes[$name] = routeRecord(
                        $route,
                        $load,
                        $warm,
                        notRunBatch('cache_seed_wait_incomplete'),
                        $cacheSeedWait,
                    );
                    $failedRoute = $name;
                    $invalidReason = 'cache_seed_condition_not_proven_on_' . $name;
                    break;
                }
                $primedAt = hrtime(true);
                $prime = get($url, $handle, $configuration['api_key']);
                $primeCompletedAt = hrtime(true);
                if (! $prime['ok']) {
                    $warm = summarizeBatch([], 1, 0, [$prime['error'] => 1], false, 'cache_prime_failed');
                    $routes[$name] = routeRecord($route, $load, $warm, notRunBatch('cache_prime_failed'), $cacheSeedWait);
                    $failedRoute = $name;
                    $invalidReason = 'request_error_on_' . $name;
                    break;
                }

                $warm = measureWarmSamples(
                    static fn(): array => get($url, $handle, $configuration['api_key']),
                    $route['cache_ttl_seconds'],
                    $primedAt,
                );
                if ($warm['p95_target_outcome'] === 'invalid') {
                    $routes[$name] = routeRecord($route, $load, $warm, notRunBatch('warm_batch_failed'), $cacheSeedWait);
                    $failedRoute = $name;
                    $invalidReason = $warm['errors'] === []
                        ? 'warm_cache_condition_not_proven_on_' . $name
                        : 'request_error_on_' . $name;
                    break;
                }

                $cold = measureColdSamples(
                    static fn(): array => get($url, $handle, $configuration['api_key']),
                    $route['cache_ttl_seconds'],
                    $primeCompletedAt,
                );
                $routes[$name] = routeRecord($route, $load, $warm, $cold, $cacheSeedWait);
                if ($cold['p95_target_outcome'] === 'invalid') {
                    $failedRoute = $name;
                    $invalidReason = $cold['errors'] === []
                        ? 'cold_cache_condition_not_proven_on_' . $name
                        : 'request_error_on_' . $name;
                    break;
                }
            }
        }
    }

    if ($failedRoute !== null) {
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
    $loadEvidence = null;
    if ($invalidReason === null && $benchmarkStartedAt instanceof DateTimeImmutable) {
        $benchmarkEndedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $endingSnapshot = readFinalLoadSnapshot();
        if ($endingSnapshot === null) {
            $invalidReason = 'load_end_snapshot_missing_or_invalid';
            $loadEvidence = ['valid' => false, 'reason' => $invalidReason];
        } else {
            $loadEvidence = assessLoadEvidence(
                $load,
                $endingSnapshot,
                $benchmarkStartedAt,
                $benchmarkEndedAt,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
            if (! $loadEvidence['valid']) {
                $invalidReason = $loadEvidence['reason'];
            }
            $routes = attachLoadEvidence($routes, $loadEvidence);
        }
    }

    if ($invalidReason !== null) {
        outputReport([
            'run_at_utc' => gmdate(DATE_ATOM),
            'valid' => false,
            'target' => $baseUrl,
            'method' => methodSummary(),
            'load_start_snapshot' => $load,
            'load_evidence' => $loadEvidence,
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
        'load_start_snapshot' => $load,
        'load_evidence' => $loadEvidence,
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

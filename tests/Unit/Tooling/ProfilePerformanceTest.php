<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use DateTimeImmutable;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__ . '/../../../tools/profile-performance.php';

final class ProfilePerformanceTest extends TestCase
{
    public function test_help_describes_the_staging_target_without_making_requests(): void
    {
        $process = new Process([PHP_BINARY, base_path('tools/profile-performance.php'), '--help'], base_path());
        $process->mustRun();

        self::assertStringContainsString('target jav.soulevil.com', $process->getOutput());
        self::assertStringContainsString('JAVMETA_STAGING_API_KEY', $process->getOutput());
        self::assertStringContainsString('legacy in-process profiler remains available', $process->getOutput());
    }

    public function test_legacy_positional_command_keeps_iteration_and_comparison_filters(): void
    {
        $script = 'tools/profile-performance.php';
        self::assertTrue(isLegacyProfilerInvocation([$script, '30']));
        self::assertFalse(isLegacyProfilerInvocation([$script, '--base-url', 'https://jav.soulevil.com']));

        $defaults = parseLegacyProfilerArguments([$script, '30'], base_path());
        self::assertSame(30, $defaults['iterations']);
        self::assertSame(base_path(), $defaults['comparison_root']);
        self::assertNull($defaults['jobs']);
        self::assertSame([], $defaults['files']);

        $filtered = parseLegacyProfilerArguments([
            $script,
            '5',
            base_path(),
            'facets,search',
            'app/Repositories/PerformerRepository.php,app/Repositories/MovieRepository.php',
        ], base_path());
        self::assertSame(5, $filtered['iterations']);
        self::assertSame(['facets', 'search'], $filtered['jobs']);
        self::assertSame([
            'app/Repositories/PerformerRepository.php',
            'app/Repositories/MovieRepository.php',
        ], $filtered['files']);
    }

    public function test_legacy_profiler_retains_jobs_and_read_only_guards(): void
    {
        $jobNames = [
            'performers',
            'performers-aiko',
            'status',
            'facets',
            'suggest',
            'search',
        ];

        self::assertSame($jobNames, LEGACY_PROFILE_JOB_NAMES);
        self::assertSame($jobNames, array_keys(legacyProfilerJobs($this->app)));
        self::assertSame([
            'CACHE_STORE' => 'array',
            'PGOPTIONS' => '-c default_transaction_read_only=on',
        ], legacyReadOnlyRuntimeEnvironment());
        self::assertSame('SET default_transaction_read_only = on', legacyReadOnlyDatabaseStatement());
    }

    public function test_legacy_positional_command_validates_iterations_before_bootstrap(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tools/profile-performance.php'),
            '0',
        ], base_path());
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Legacy profiling failed: InvalidArgumentException', $process->getErrorOutput());
    }

    public function test_legacy_profiler_rejects_out_of_range_iterations_unknown_jobs_and_paths_outside_root(): void
    {
        $script = 'tools/profile-performance.php';
        foreach ([
            [$script, '0'],
            [$script, '-1'],
            [$script, '1001'],
            [$script, '1', base_path(), 'unknown'],
            [$script, '1', base_path(), 'facets', '../outside.php'],
        ] as $arguments) {
            try {
                parseLegacyProfilerArguments($arguments, base_path());
                self::fail('Unexpectedly accepted legacy profiler arguments.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_it_rejects_the_old_staging_hostname_before_dns_or_http(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tools/profile-performance.php'),
            ...$this->validOptions('https://jav.soulev.com'),
        ], base_path(), ['JAVMETA_STAGING_API_KEY' => 'test-placeholder']);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('HTTPS staging origin', $process->getErrorOutput());
        self::assertStringNotContainsString('test-placeholder', $process->getOutput() . $process->getErrorOutput());
    }

    public function test_it_refuses_to_start_without_an_explicit_staging_authorization_flag(): void
    {
        $options = $this->validOptions('https://jav.soulevil.com');
        array_splice($options, -3, 1);
        $process = new Process([
            PHP_BINARY,
            base_path('tools/profile-performance.php'),
            ...$options,
        ], base_path(), ['JAVMETA_STAGING_API_KEY' => null]);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('Missing required confirmation --authorized-staging', $process->getErrorOutput());
    }

    public function test_it_accepts_only_the_canonical_https_staging_origin(): void
    {
        self::assertSame('https://jav.soulevil.com', validateBaseUrl('https://jav.soulevil.com/'));

        foreach ([
            'https://jav.soulev.com',
            'https://jav.soulevil.com.attacker.test',
            'http://jav.soulevil.com',
            'https://user@jav.soulevil.com',
            'https://jav.soulevil.com:8443',
            'https://jav.soulevil.com/path',
            'https://jav.soulevil.com?target=other',
            'https://jav.soulevil.com#fragment',
        ] as $origin) {
            try {
                validateBaseUrl($origin);
                self::fail('Unexpectedly accepted staging origin: ' . $origin);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_warm_samples_are_invalid_when_the_prime_already_exceeded_the_ttl(): void
    {
        $now = 16_000_000_000;
        $requests = 0;
        $batch = measureWarmSamples(
            function () use (&$requests): array {
                $requests++;

                return $this->response(20.0);
            },
            15,
            0,
            static function () use (&$now): int {
                return $now;
            },
        );

        self::assertSame(0, $requests);
        self::assertSame('invalid', $batch['p95_target_outcome']);
        self::assertFalse($batch['cache_condition_verified']);
        self::assertSame('prime_expired_before_warm_samples', $batch['reason']);
    }

    public function test_all_route_ttls_allow_thirty_verified_warm_samples_when_the_batch_fits(): void
    {
        self::assertSame(
            ['search' => 60, 'status' => 15, 'movie_facets' => 60, 'performer_facets' => 60],
            array_map(static fn(array $route): int => $route['cache_ttl_seconds'], ROUTES),
        );

        foreach (ROUTES as $route) {
            $now = 0;
            $batch = measureWarmSamples(
                function () use (&$now): array {
                    $now += 20_000_000;

                    return $this->response(20.0);
                },
                $route['cache_ttl_seconds'],
                0,
                static function () use (&$now): int {
                    return $now;
                },
            );

            self::assertSame(30, $batch['verified_warm_samples']);
            self::assertSame('met', $batch['p95_target_outcome']);
            self::assertTrue($batch['cache_condition_verified']);
        }
    }

    public function test_warm_samples_are_invalid_when_the_batch_rolls_over_the_ttl(): void
    {
        $now = 0;
        $requests = 0;
        $batch = measureWarmSamples(
            function () use (&$now, &$requests): array {
                $requests++;
                $now += 1_000_000_000;

                return $this->response(20.0);
            },
            15,
            0,
            static function () use (&$now): int {
                return $now;
            },
        );

        self::assertSame(15, $requests);
        self::assertSame(14, $batch['verified_warm_samples']);
        self::assertSame('invalid', $batch['p95_target_outcome']);
        self::assertSame('warm_sample_completed_after_expiry', $batch['reason']);
    }

    public function test_cold_samples_are_independently_after_expiry_and_use_all_latencies_for_p95(): void
    {
        $now = 0;
        $latencies = array_merge(array_fill(0, 15, 20.0), array_fill(0, 15, 500.0));
        $requests = 0;
        $batch = measureColdSamples(
            function () use (&$now, &$latencies, &$requests): array {
                $latency = $latencies[$requests++];
                $now += (int) ($latency * 1_000_000);

                return $this->response($latency);
            },
            15,
            0,
            static function () use (&$now): int {
                return $now;
            },
            static function (int $deadline) use (&$now): void {
                $now += $deadline;
            },
        );

        self::assertSame(30, $requests);
        self::assertSame(30, $batch['verified_after_expiry_samples']);
        self::assertCount(30, $batch['expiry_wait_seconds']);
        self::assertTrue(min($batch['expiry_wait_seconds']) >= 16.0);
        self::assertSame(500.0, $batch['p95_ms']);
        self::assertSame('not_met', $batch['p95_target_outcome']);
        self::assertTrue($batch['cache_condition_verified']);
    }

    public function test_first_cold_outlier_is_reported_separately_from_the_p95_of_thirty_cold_samples(): void
    {
        $now = 0;
        $latencies = array_merge([500.0], array_fill(0, 29, 20.0));
        $requests = 0;
        $batch = measureColdSamples(
            function () use (&$now, &$latencies, &$requests): array {
                $latency = $latencies[$requests++];
                $now += (int) ($latency * 1_000_000);

                return $this->response($latency);
            },
            15,
            0,
            static function () use (&$now): int {
                return $now;
            },
            static function (int $remaining) use (&$now): void {
                $now += $remaining;
            },
        );

        self::assertSame(30, $batch['verified_after_expiry_samples']);
        self::assertSame(500.0, $batch['first_after_expiry_ms']);
        self::assertSame('at_or_over_400_ms', $batch['first_after_expiry_target_outcome']);
        self::assertSame(20.0, $batch['p95_ms']);
        self::assertSame('met', $batch['p95_target_outcome']);
    }

    public function test_cold_sampling_fails_closed_when_the_expiry_wait_does_not_complete(): void
    {
        $now = 0;
        $requests = 0;
        $batch = measureColdSamples(
            static function () use (&$requests): array {
                $requests++;

                return $this->response(20.0);
            },
            15,
            0,
            static function () use (&$now): int {
                return $now;
            },
            static function (int $deadline): void {},
        );

        self::assertSame(0, $requests);
        self::assertSame('invalid', $batch['p95_target_outcome']);
        self::assertFalse($batch['cache_condition_verified']);
        self::assertSame('expiry_wait_incomplete', $batch['reason']);
    }

    public function test_every_route_ttl_is_waited_before_each_cold_sample(): void
    {
        foreach (ROUTES as $route) {
            $now = 0;
            $requests = 0;
            $batch = measureColdSamples(
                function () use (&$now, &$requests): array {
                    $requests++;
                    $now += 20_000_000;

                    return $this->response(20.0);
                },
                $route['cache_ttl_seconds'],
                0,
                static function () use (&$now): int {
                    return $now;
                },
                static function (int $remaining) use (&$now): void {
                    $now += $remaining;
                },
            );

            self::assertSame(30, $requests);
            self::assertSame(30, $batch['verified_after_expiry_samples']);
            self::assertTrue(min($batch['expiry_wait_seconds']) >= $route['cache_ttl_seconds'] + 1);
        }
    }

    public function test_a_cold_request_error_invalidates_the_entire_case(): void
    {
        $now = 0;
        $batch = measureColdSamples(
            static fn(): array => ['ok' => false, 'error' => 'timeout'],
            15,
            0,
            static function () use (&$now): int {
                return $now;
            },
            static function (int $remaining) use (&$now): void {
                $now += $remaining;
            },
        );

        self::assertSame(['timeout' => 1], $batch['errors']);
        self::assertSame('invalid', $batch['p95_target_outcome']);
        self::assertFalse($batch['cache_condition_verified']);
    }

    public function test_load_evidence_rejects_stale_or_future_start_snapshots(): void
    {
        $runStart = new DateTimeImmutable('2026-11-11T12:01:00Z');
        $runEnd = new DateTimeImmutable('2026-11-11T12:02:00Z');
        $now = new DateTimeImmutable('2026-11-11T12:03:00Z');
        $end = $this->loadSnapshot('2026-11-11T12:02:01Z', true);

        foreach (['2026-11-11T11:59:59Z', '2026-11-11T12:01:01Z'] as $capturedAt) {
            $evidence = assessLoadEvidence(
                $this->loadSnapshot($capturedAt, true),
                $end,
                $runStart,
                $runEnd,
                $now,
            );

            self::assertFalse($evidence['valid']);
            self::assertSame('load_start_snapshot_stale_or_future', $evidence['reason']);
        }
    }

    public function test_load_evidence_rejects_stale_future_and_nonquiet_end_snapshots(): void
    {
        $start = $this->loadSnapshot('2026-11-11T12:00:30Z', true);
        $runStart = new DateTimeImmutable('2026-11-11T12:01:00Z');
        $runEnd = new DateTimeImmutable('2026-11-11T12:02:00Z');
        $now = new DateTimeImmutable('2026-11-11T12:03:00Z');

        foreach ([
            [$this->loadSnapshot('2026-11-11T12:00:59Z', true), 'load_end_snapshot_stale_or_future'],
            [$this->loadSnapshot('2026-11-11T12:03:01Z', true), 'load_end_snapshot_stale_or_future'],
            [$this->loadSnapshot('2026-11-11T12:02:01Z', false), 'load_interval_not_confirmed_quiet'],
        ] as [$end, $expectedReason]) {
            $evidence = assessLoadEvidence($start, $end, $runStart, $runEnd, $now);

            self::assertFalse($evidence['valid']);
            self::assertSame($expectedReason, $evidence['reason']);
        }
    }

    public function test_load_evidence_records_start_and_end_snapshots_for_the_full_interval(): void
    {
        $start = $this->loadSnapshot('2026-11-11T12:00:30Z', true);
        $end = $this->loadSnapshot('2026-11-11T12:02:01Z', true);
        $evidence = assessLoadEvidence(
            $start,
            $end,
            new DateTimeImmutable('2026-11-11T12:01:00Z'),
            new DateTimeImmutable('2026-11-11T12:02:00Z'),
            new DateTimeImmutable('2026-11-11T12:03:00Z'),
        );

        self::assertTrue($evidence['valid']);
        self::assertSame('2026-11-11T12:00:30+00:00', $evidence['start_snapshot']['captured_at']);
        self::assertSame('2026-11-11T12:02:01+00:00', $evidence['end_snapshot']['captured_at']);
        self::assertSame([0.1, 0.1, 0.1], $evidence['start_snapshot']['host_load_average_1m_5m_15m']);
        self::assertTrue($evidence['operator_confirmed_quiet_for_interval']);
    }

    public function test_load_evidence_rejects_a_changed_source_and_a_nonquiet_load_change(): void
    {
        $start = $this->loadSnapshot('2026-11-11T12:00:30Z', true);
        $runStart = new DateTimeImmutable('2026-11-11T12:01:00Z');
        $runEnd = new DateTimeImmutable('2026-11-11T12:02:00Z');
        $now = new DateTimeImmutable('2026-11-11T12:03:00Z');
        $otherSource = $this->loadSnapshot('2026-11-11T12:02:01Z', true);
        $otherSource['source'] = 'periodic-report';
        $changedLoad = $this->loadSnapshot('2026-11-11T12:02:01Z', false);
        $changedLoad['host_load_average_1m_5m_15m'] = [32.0, 26.0, 18.0];
        $changedLoad['database_cpu_percent'] = 95.0;

        self::assertSame(
            'load_snapshot_source_mismatch',
            assessLoadEvidence($start, $otherSource, $runStart, $runEnd, $now)['reason'],
        );
        self::assertSame(
            'load_interval_not_confirmed_quiet',
            assessLoadEvidence($start, $changedLoad, $runStart, $runEnd, $now)['reason'],
        );
    }

    /** @return list<string> */
    private function validOptions(string $baseUrl): array
    {
        return [
            '--base-url', $baseUrl,
            '--host-load-average', '0.1,0.1,0.1',
            '--db-cpu-percent', '0',
            '--db-memory-percent', '0',
            '--load-captured-at', gmdate(DATE_ATOM),
            '--load-source', 'openobserve',
            '--authorized-staging', '--confirm-quiet-load', '--confirm-cold-cache',
        ];
    }

    /** @return array{ok: bool, status: int, elapsed_ms: float, error: ?string, body: string} */
    private function response(float $elapsedMs): array
    {
        return ['ok' => true, 'status' => 200, 'elapsed_ms' => $elapsedMs, 'error' => null, 'body' => ''];
    }

    /** @return array<string, mixed> */
    private function loadSnapshot(string $capturedAt, bool $quiet): array
    {
        return [
            'source' => 'openobserve',
            'captured_at' => $capturedAt,
            'host_load_average_1m_5m_15m' => [0.1, 0.1, 0.1],
            'database_cpu_percent' => 10.0,
            'database_memory_percent' => 20.0,
            'operator_confirmed_quiet_for_interval' => $quiet,
        ];
    }
}

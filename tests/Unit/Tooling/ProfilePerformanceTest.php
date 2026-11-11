<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProfilePerformanceTest extends TestCase
{
    public function test_help_describes_the_staging_target_without_making_requests(): void
    {
        $process = new Process([PHP_BINARY, base_path('tools/profile-performance.php'), '--help'], base_path());
        $process->mustRun();

        self::assertStringContainsString('target jav.soulev.com', $process->getOutput());
        self::assertStringContainsString('JAVMETA_STAGING_API_KEY', $process->getOutput());
    }

    public function test_it_rejects_a_non_staging_origin_before_dns_or_http(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tools/profile-performance.php'),
            ...$this->validOptions('https://jav.soulev.com.attacker.test'),
        ], base_path(), ['JAVMETA_STAGING_API_KEY' => 'test-placeholder']);
        $process->run();

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('HTTPS staging origin', $process->getErrorOutput());
        self::assertStringNotContainsString('test-placeholder', $process->getOutput() . $process->getErrorOutput());
    }

    public function test_it_refuses_to_start_without_an_explicit_staging_authorization_flag(): void
    {
        $options = $this->validOptions('https://jav.soulev.com');
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

    /** @return list<string> */
    private function validOptions(string $baseUrl): array
    {
        return [
            '--base-url', $baseUrl,
            '--host-load-average', '0.1,0.1,0.1',
            '--db-cpu-percent', '0',
            '--db-memory-percent', '0',
            '--load-captured-at', '2026-11-11T12:00:00Z',
            '--load-source', 'openobserve',
            '--authorized-staging', '--confirm-quiet-load', '--confirm-cold-cache',
        ];
    }
}

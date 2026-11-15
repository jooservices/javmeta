<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CoverageEnforceTest extends TestCase
{
    public function test_it_fails_when_the_coverage_report_is_below_the_configured_threshold(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tools/coverage-enforce.php'),
            base_path('tests/Fixtures/coverage/below-90.xml'),
            '90',
        ], base_path());
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getErrorOutput());
        self::assertStringContainsString('Coverage: 89.00% (89/100 statements); minimum 90.00%', $process->getOutput());
    }

    public function test_per_file_coverage_fails_when_the_combined_report_has_no_measurable_app_statements(): void
    {
        $process = $this->runPerFileCoverage('empty-clover.xml');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('No measurable app statement lines', $process->getErrorOutput());
        self::assertStringNotContainsString('Per-file coverage:', $process->getOutput());
    }

    public function test_per_file_coverage_fails_when_reports_contain_only_non_app_paths(): void
    {
        $process = $this->runPerFileCoverage('non-app-only-clover.xml');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('No measurable app statement lines', $process->getErrorOutput());
        self::assertStringNotContainsString('Per-file coverage:', $process->getOutput());
    }

    public function test_per_file_coverage_passes_at_the_configured_boundary(): void
    {
        $process = $this->runPerFileCoverage('per-file-85-percent.xml');

        self::assertSame(0, $process->getExitCode());
        self::assertSame('', $process->getErrorOutput());
        self::assertStringContainsString(
            'Per-file coverage: 85.00% overall (17/20 statements across 1 measurable app files); floor 85.00%',
            $process->getOutput(),
        );
        self::assertStringContainsString('Files below floor: 0', $process->getOutput());
    }

    public function test_per_file_coverage_allows_empty_inputs_when_the_combined_reports_are_measurable(): void
    {
        $process = $this->runPerFileCoverage('empty-clover.xml', 'per-file-85-percent.xml');

        self::assertSame(0, $process->getExitCode());
        self::assertSame('', $process->getErrorOutput());
        self::assertStringContainsString('(17/20 statements across 1 measurable app files)', $process->getOutput());
    }

    public function test_per_file_coverage_fails_below_the_configured_boundary(): void
    {
        $process = $this->runPerFileCoverage('per-file-below-85-percent.xml');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Per-file coverage: 80.00% overall (16/20 statements across 1 measurable app files)', $process->getOutput());
        self::assertStringContainsString('Files below floor: 1', $process->getOutput());
    }

    public function test_per_file_coverage_fails_for_a_missing_report(): void
    {
        $process = $this->runPerFileCoverage('missing-clover.xml');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Coverage file ', $process->getErrorOutput());
        self::assertStringContainsString(' not found.', $process->getErrorOutput());
    }

    public function test_per_file_coverage_fails_for_malformed_xml(): void
    {
        $process = $this->runPerFileCoverage('malformed-clover.xml');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('is not valid XML.', $process->getErrorOutput());
    }

    private function runPerFileCoverage(string ...$reports): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tools/quality/coverage-per-file.php'),
            '85',
            ...array_map(
                static fn(string $report): string => base_path('tests/Fixtures/coverage/' . $report),
                $reports,
            ),
        ], base_path());
        $process->run();

        return $process;
    }
}

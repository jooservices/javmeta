<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CoverageReportTest extends TestCase
{
    public function test_it_lists_uncovered_lines_and_the_boundary_reason_from_clover(): void
    {
        $reportPath = tempnam(sys_get_temp_dir(), 'javmeta-coverage-report-');
        if ($reportPath === false) {
            throw new RuntimeException('Unable to allocate a temporary coverage report path.');
        }

        try {
            $process = new Process([
                PHP_BINARY,
                base_path('tools/coverage-report.php'),
                base_path('tests/Fixtures/coverage/report-sample.xml'),
                $reportPath,
            ], base_path());
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString('Wrote 1 uncovered-file entries', $process->getOutput());

            $report = file_get_contents($reportPath);
            self::assertIsString($report);
            self::assertStringContainsString('**50.00% (1/2)**', $report);
            self::assertStringContainsString('`app/Http/Controllers/McpHttpController.php` | 48 |', $report);
            self::assertStringContainsString('SSE response callback is not drained', $report);
        } finally {
            if (is_file($reportPath)) {
                unlink($reportPath);
            }
        }
    }
}

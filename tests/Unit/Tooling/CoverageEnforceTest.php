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
}

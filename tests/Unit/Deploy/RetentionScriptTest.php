<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

final class RetentionScriptTest extends TestCase
{
    public function test_retention_keeps_path_boundaries_and_never_deletes_a_sibling(): void
    {
        $script = dirname(__DIR__, 3) . '/tests/Shell/retention.test.sh';
        exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('PASS:', implode("\n", $output));
    }
}

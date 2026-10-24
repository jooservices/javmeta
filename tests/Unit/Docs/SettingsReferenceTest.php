<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SettingsReferenceTest extends TestCase
{
    public function test_the_checked_in_reference_matches_the_standalone_generator(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $root . '/tools/docs/settings-reference.php']);
        $process->mustRun();

        $this->assertSame(file_get_contents($root . '/docs/user-guide/settings-reference.md'), $process->getOutput());
    }

    public function test_every_group_label_description_and_key_is_in_the_reference(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = require $root . '/config/javmeta_settings.php';
        $reference = (string) file_get_contents($root . '/docs/user-guide/settings-reference.md');

        foreach ($schema['groups'] as $label) {
            $this->assertStringContainsString('## ' . $label, $reference);
        }
        foreach ($schema['definitions'] as $key => $definition) {
            $this->assertStringContainsString('`' . $key . '`', $reference);
            $this->assertStringContainsString($definition['label'], $reference);
            if ($definition['description'] !== '') {
                $this->assertStringContainsString($definition['description'], $reference);
            }
        }
    }
}

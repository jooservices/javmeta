<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StartupCommandBootstrapTest extends TestCase
{
    public function test_package_discovery_does_not_resolve_the_encrypted_settings_store_without_an_app_key(): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'package:discover', '--ansi'],
            base_path(),
            ['APP_ENV' => 'production', 'APP_KEY' => ''],
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }
}

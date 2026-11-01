<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class EnvironmentBootstrapAllowlistTest extends TestCase
{
    public function test_config_environment_reads_use_only_the_documented_bootstrap_allowlist(): void
    {
        $root = dirname(__DIR__, 3);
        $allowlist = require $root . '/config/bootstrap_env_allowlist.php';
        $unknown = [];

        foreach (glob($root . '/config/*.php') ?: [] as $path) {
            $contents = file_get_contents($path);
            preg_match_all('/\benv\s*\(\s*[\'\"]([A-Z][A-Z0-9_]*)[\'\"]/m', $contents, $matches);
            foreach ($matches[1] as $key) {
                if (! in_array($key, $allowlist, true)) {
                    $unknown[] = basename($path) . ':' . $key;
                }
            }
        }

        $this->assertSame([], $unknown, "Add only bootstrap keys to config/bootstrap_env_allowlist.php; move runtime tunables to database settings:\n" . implode("\n", $unknown));
    }
}

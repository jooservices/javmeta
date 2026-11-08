<?php

declare(strict_types=1);

namespace Tests\Unit\Observability\Sentry;

use Tests\TestCase;

final class SentryConfigTest extends TestCase
{
    public function test_sentry_laravel_dsn_and_environment_aliases_are_supported(): void
    {
        $previous = $this->setEnvironment([
            'JAVMETA_SENTRY_DSN' => '',
            'SENTRY_LARAVEL_DSN' => 'https://public@example.test/1',
            'APP_ENV' => 'testing',
            'SENTRY_ENVIRONMENT' => 'staging',
        ]);

        try {
            $config = require config_path('sentry.php');

            self::assertSame('https://public@example.test/1', $config['dsn']);
            self::assertSame('staging', $config['environment']);
        } finally {
            $this->restoreEnvironment($previous);
        }
    }

    public function test_javmeta_sentry_dsn_takes_precedence_over_the_alias(): void
    {
        $previous = $this->setEnvironment([
            'JAVMETA_SENTRY_DSN' => 'https://legacy@example.test/1',
            'SENTRY_LARAVEL_DSN' => 'https://public@example.test/2',
        ]);

        try {
            $config = require config_path('sentry.php');

            self::assertSame('https://legacy@example.test/1', $config['dsn']);
        } finally {
            $this->restoreEnvironment($previous);
        }
    }

    /**
     * @param array<string, string> $values
     * @return array<string, array{getenv: string|false, env: mixed, env_exists: bool, server: mixed, server_exists: bool}>
     */
    private function setEnvironment(array $values): array
    {
        $previous = [];

        foreach ($values as $name => $value) {
            $previous[$name] = [
                'getenv' => getenv($name),
                'env' => $_ENV[$name] ?? null,
                'env_exists' => array_key_exists($name, $_ENV),
                'server' => $_SERVER[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
            ];
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return $previous;
    }

    /** @param array<string, array{getenv: string|false, env: mixed, env_exists: bool, server: mixed, server_exists: bool}> $previous */
    private function restoreEnvironment(array $previous): void
    {
        foreach ($previous as $name => $values) {
            if ($values['getenv'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $values['getenv']);
            }

            if ($values['env_exists']) {
                $_ENV[$name] = $values['env'];
            } else {
                unset($_ENV[$name]);
            }

            if ($values['server_exists']) {
                $_SERVER[$name] = $values['server'];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
}

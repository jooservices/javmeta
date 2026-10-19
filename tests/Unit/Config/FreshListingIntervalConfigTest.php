<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class FreshListingIntervalConfigTest extends TestCase
{
    public function test_fresh_listing_interval_defaults_to_sixty_minutes(): void
    {
        self::assertSame(60, $this->freshIntervalWithEnvironmentValue(null));
    }

    public function test_fresh_listing_interval_uses_the_environment_value(): void
    {
        self::assertSame(25, $this->freshIntervalWithEnvironmentValue('25'));
    }

    private function freshIntervalWithEnvironmentValue(?string $value): int
    {
        $name = 'JAVMETA_FRESH_INTERVAL_MINUTES';
        $previousProcessValue = getenv($name);
        $hadEnvValue = array_key_exists($name, $_ENV);
        $previousEnvValue = $_ENV[$name] ?? null;
        $hadServerValue = array_key_exists($name, $_SERVER);
        $previousServerValue = $_SERVER[$name] ?? null;

        try {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            $config = require dirname(__DIR__, 3) . '/config/javmeta_sources.php';

            return $config['defaults']['loop']['fresh_interval_minutes'];
        } finally {
            if ($previousProcessValue === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previousProcessValue);
            }

            if ($hadEnvValue) {
                $_ENV[$name] = $previousEnvValue;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServerValue) {
                $_SERVER[$name] = $previousServerValue;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
}

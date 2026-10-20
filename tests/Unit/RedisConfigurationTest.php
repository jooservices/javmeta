<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class RedisConfigurationTest extends TestCase
{
    public function test_phpredis_extension_is_loaded_and_selected(): void
    {
        self::assertTrue(extension_loaded('redis'));
        self::assertSame('phpredis', config('database.redis.client'));
    }

    public function test_default_queue_uses_redis_while_cache_and_session_remain_database_backed(): void
    {
        $variableNames = ['QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER'];
        $originalValues = [];

        foreach ($variableNames as $variableName) {
            $originalValues[$variableName] = [
                'process' => getenv($variableName),
                'env_exists' => array_key_exists($variableName, $_ENV),
                'env' => $_ENV[$variableName] ?? null,
                'server_exists' => array_key_exists($variableName, $_SERVER),
                'server' => $_SERVER[$variableName] ?? null,
            ];

            unset($_ENV[$variableName], $_SERVER[$variableName]);
            putenv($variableName);
        }

        try {
            $queueConfig = require config_path('queue.php');
            self::assertSame('redis', $queueConfig['default']);
            self::assertSame('database', $queueConfig['connections']['database']['driver']);
            self::assertSame('database', (require config_path('cache.php'))['default']);
            self::assertSame('database', (require config_path('session.php'))['driver']);
        } finally {
            foreach ($originalValues as $variableName => $originalValue) {
                if ($originalValue['process'] === false) {
                    putenv($variableName);
                } else {
                    putenv($variableName . '=' . $originalValue['process']);
                }

                if ($originalValue['env_exists']) {
                    $_ENV[$variableName] = $originalValue['env'];
                } else {
                    unset($_ENV[$variableName]);
                }

                if ($originalValue['server_exists']) {
                    $_SERVER[$variableName] = $originalValue['server'];
                } else {
                    unset($_SERVER[$variableName]);
                }
            }
        }
    }
}

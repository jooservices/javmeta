<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class MongoDatabaseConfigTest extends TestCase
{
    public function test_legacy_mongo_environment_names_remain_supported(): void
    {
        $configuration = $this->readConfiguration([
            'MONGO_URI' => 'mongodb://archive-legacy.test:27017/legacy',
            'MONGO_DATABASE' => 'legacy_archive',
            'MONGO_HOST' => 'legacy-host.test',
            'MONGO_PORT' => '27018',
        ]);

        self::assertSame('mongodb://archive-legacy.test:27017/legacy', $configuration['database']['connections']['mongodb']['dsn']);
        self::assertSame('legacy_archive', $configuration['database']['connections']['mongodb']['database']);
        self::assertSame('mongodb://archive-legacy.test:27017/legacy', $configuration['mongodb']['uri']);
        self::assertSame('legacy_archive', $configuration['mongodb']['database']);
    }

    public function test_shared_mongodb_names_take_precedence_when_both_name_sets_are_present(): void
    {
        $configuration = $this->readConfiguration([
            'MONGODB_URI' => 'mongodb://archive-shared.test:27017/shared',
            'MONGO_URI' => 'mongodb://archive-legacy.test:27017/legacy',
            'MONGODB_DATABASE' => 'shared_archive',
            'MONGO_DATABASE' => 'legacy_archive',
        ]);

        self::assertSame('mongodb://archive-shared.test:27017/shared', $configuration['database']['connections']['mongodb']['dsn']);
        self::assertSame('shared_archive', $configuration['database']['connections']['mongodb']['database']);
        self::assertSame('mongodb://archive-shared.test:27017/shared', $configuration['mongodb']['uri']);
        self::assertSame('shared_archive', $configuration['mongodb']['database']);
    }

    /**
     * @param array<string, string> $environment
     * @return array{database: array<string, mixed>, mongodb: array<string, mixed>}
     */
    private function readConfiguration(array $environment): array
    {
        $names = ['MONGODB_URI', 'MONGO_URI', 'MONGODB_DATABASE', 'MONGO_DATABASE', 'MONGO_HOST', 'MONGO_PORT'];
        $previous = [];

        foreach ($names as $name) {
            $previous[$name] = [
                'environment' => getenv($name),
                'environment_exists' => getenv($name) !== false,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
            ];

            if (array_key_exists($name, $environment)) {
                $value = $environment[$name];
                putenv($name . '=' . $value);
                $_SERVER[$name] = $value;
                $_ENV[$name] = $value;
            } else {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            }
        }

        try {
            $root = dirname(__DIR__, 3);

            return [
                'database' => require $root . '/config/database.php',
                'mongodb' => require $root . '/config/mongodb.php',
            ];
        } finally {
            foreach ($previous as $name => $values) {
                if ($values['environment_exists']) {
                    putenv($name . '=' . $values['environment']);
                } else {
                    putenv($name);
                }

                if ($values['server_exists']) {
                    $_SERVER[$name] = $values['server'];
                } else {
                    unset($_SERVER[$name]);
                }

                if ($values['env_exists']) {
                    $_ENV[$name] = $values['env'];
                } else {
                    unset($_ENV[$name]);
                }
            }
        }
    }
}

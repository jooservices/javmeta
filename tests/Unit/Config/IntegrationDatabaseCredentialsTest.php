<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class IntegrationDatabaseCredentialsTest extends TestCase
{
    private const DATABASE_ENVIRONMENT = [
        'POSTGRES_DB' => 'DB_DATABASE',
        'POSTGRES_USER' => 'DB_USERNAME',
        'POSTGRES_PASSWORD' => 'DB_PASSWORD',
    ];

    public function test_postgres_credentials_match_phpunit_integration_config(): void
    {
        $root = dirname(__DIR__, 3);
        $composeContents = file_get_contents($root . '/docker/ci/compose.integration.yml');
        self::assertNotFalse($composeContents, 'Unable to read the integration Compose file.');

        $configuration = simplexml_load_file($root . '/phpunit.integration.xml');
        self::assertInstanceOf(SimpleXMLElement::class, $configuration, 'Unable to read the PHPUnit integration configuration.');

        foreach (self::DATABASE_ENVIRONMENT as $postgresName => $phpunitName) {
            self::assertSame(
                $this->phpunitEnvironmentValue($configuration, $phpunitName),
                $this->composeEnvironmentValue($composeContents, $postgresName),
                sprintf('%s must match phpunit.integration.xml %s.', $postgresName, $phpunitName),
            );
        }
    }

    public function test_postgres_healthcheck_matches_the_service_database_and_user(): void
    {
        $root = dirname(__DIR__, 3);
        $composeContents = file_get_contents($root . '/docker/ci/compose.integration.yml');
        self::assertNotFalse($composeContents, 'Unable to read the integration Compose file.');

        $matches = [];
        self::assertSame(
            1,
            preg_match('/pg_isready\s+-U\s+([^\s"\']+)\s+-d\s+([^\s"\']+)/', $composeContents, $matches),
            'Unable to read the Postgres healthcheck database and user.',
        );

        self::assertSame(
            $this->composeEnvironmentValue($composeContents, 'POSTGRES_USER'),
            $matches[1] ?? '',
            'The Postgres healthcheck user must match POSTGRES_USER.',
        );
        self::assertSame(
            $this->composeEnvironmentValue($composeContents, 'POSTGRES_DB'),
            $matches[2] ?? '',
            'The Postgres healthcheck database must match POSTGRES_DB.',
        );
    }

    private function composeEnvironmentValue(string $contents, string $name): string
    {
        $matches = [];
        self::assertSame(
            1,
            preg_match('/^\h*' . preg_quote($name, '/') . ':\h*([^\s#]+)\h*$/m', $contents, $matches),
            sprintf('Missing %s in docker/ci/compose.integration.yml.', $name),
        );

        return $matches[1] ?? '';
    }

    private function phpunitEnvironmentValue(SimpleXMLElement $configuration, string $name): string
    {
        foreach ($configuration->php->env as $environment) {
            if ((string) $environment['name'] === $name) {
                return (string) $environment['value'];
            }
        }

        self::fail(sprintf('Missing %s in phpunit.integration.xml.', $name));

        return '';
    }
}

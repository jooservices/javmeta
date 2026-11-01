<?php

declare(strict_types=1);

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

final class DeploymentRuntimeTest extends TestCase
{
    public function test_runtime_gate_requires_healthy_horizon_and_only_the_requested_scheduler(): void
    {
        require_once dirname(__DIR__, 3) . '/deploy/lib/readiness.php';
        $running = [(object) ['status' => 'running']];
        $this->assertFalse(\JavMeta\Deploy\runtimeReady([], false, false));
        $this->assertFalse(\JavMeta\Deploy\runtimeReady([(object) ['status' => 'paused']], false, false));
        $this->assertFalse(\JavMeta\Deploy\runtimeReady([(object) []], false, false));
        $this->assertTrue(\JavMeta\Deploy\runtimeReady($running, false, false));
        $this->assertFalse(\JavMeta\Deploy\runtimeReady($running, true, false));
        $this->assertTrue(\JavMeta\Deploy\runtimeReady($running, true, true));
    }

    public function test_mongo_credentials_are_encoded_without_losing_tls_or_replica_options(): void
    {
        require_once dirname(__DIR__, 3) . '/deploy/lib/mongo-uri.php';
        $uri = \JavMeta\Deploy\mongoUri([
            'MONGO_URI' => 'mongodb://old.invalid/db?tls=true&tlsCAFile=%2Fprivate%2Fca.pem&replicaSet=rs0&authSource=old',
            'MONGO_USERNAME' => 'user@node', 'MONGO_PASSWORD' => 'p@ss:/?#',
            'MONGO_HOST' => 'database.test', 'MONGO_PORT' => '27017',
            'MONGO_DATABASE' => 'catalog', 'MONGO_AUTH_SOURCE' => 'catalog',
        ]);
        $this->assertStringStartsWith('mongodb://user%40node:p%40ss%3A%2F%3F%23@database.test:27017/catalog?', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $options);
        $this->assertSame(['tls' => 'true', 'tlsCAFile' => '/private/ca.pem', 'replicaSet' => 'rs0', 'authSource' => 'catalog'], $options);
    }
}

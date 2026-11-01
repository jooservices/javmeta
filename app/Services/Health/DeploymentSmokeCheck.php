<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Services\Auth\ApiKeyService;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Throwable;

final class DeploymentSmokeCheck
{
    /** @return array{name: string, status: string, message: string} */
    private function check(string $name, string $status, string $message): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message];
    }

    /**
     * @return list<array{name: string, status: string, message: string}>
     */
    public function run(
        StatusHealthCheck $health,
        DependencyMonitor $dependencyMonitor,
        SchedulerHeartbeat $schedulerHeartbeat,
        MasterSupervisorRepository $horizon,
        ApiKeyService $apiKeys,
        HubInterface $sentry,
    ): array {
        $healthCheckFailed = false;
        try {
            $healthStatus = $health->status();
        } catch (Throwable) {
            $healthCheckFailed = true;
            $healthStatus = ['dependencies' => $dependencyMonitor->statuses()];
        }
        $dependencies = $healthStatus['dependencies'] ?? [];
        $checks = [
            $this->dependencyCheck('database', Dependency::Postgres, $dependencies),
            $this->dependencyCheck('redis', Dependency::Redis, $dependencies),
            $this->dependencyCheck('mongodb', Dependency::Mongo, $dependencies),
            $this->dependencyCheck(
                'elasticsearch',
                Dependency::Elasticsearch,
                $dependencies,
                (bool) config('elasticsearch.enabled', true),
            ),
            $this->dependencyCheck(
                'embedder',
                Dependency::Embedder,
                $dependencies,
                (bool) config('elasticsearch.enabled', true) && (bool) config('elasticsearch.embedder_enabled', true),
            ),
            $this->horizonCheck($horizon),
            $this->schedulerCheck($schedulerHeartbeat),
            $this->queueDepthCheck(),
        ];
        if ($healthCheckFailed) {
            $checks[] = $this->check(
                'application_health',
                'fail',
                'The health controller could not read application runtime status.',
            );
        }

        foreach ($this->apiChecks($apiKeys) as $apiCheck) {
            $checks[] = $apiCheck;
        }

        $checks[] = $this->sentryCheck($sentry);
        $checks[] = $this->dependencyCheck(
            'openobserve',
            Dependency::Observability,
            $dependencies,
            (bool) config('openobserve.enabled', false),
        );

        if (! (bool) config('javmeta.ui_login_required', true)) {
            $checks[] = $this->check('ui_login', 'warn', 'UI login disabled (guest mode)');
        }

        return $checks;
    }

    /**
     * @param  array<string, array{available?: bool}>  $dependencies
     * @return array{name: string, status: string, message: string}
     */
    private function dependencyCheck(
        string $name,
        Dependency $dependency,
        array $dependencies,
        bool $enabled = true,
    ): array {
        if (! $enabled) {
            return $this->check($name, 'skipped', ucfirst($name) . ' is disabled by configuration.');
        }

        $available = $dependencies[$dependency->value]['available'] ?? false;
        if ($available) {
            return $this->check($name, 'pass', ucfirst($name) . ' health probe succeeded.');
        }

        return $this->check($name, 'fail', ucfirst($name) . ' health probe failed.');
    }

    /** @return array{name: string, status: string, message: string} */
    private function horizonCheck(MasterSupervisorRepository $horizon): array
    {
        try {
            $masters = $horizon->all();
        } catch (Throwable) {
            return $this->check('horizon', 'fail', 'Horizon status could not be read.');
        }

        if ($masters === []) {
            return $this->check('horizon', 'fail', 'No Horizon master supervisor is running.');
        }

        foreach ($masters as $master) {
            if (($master->status ?? null) !== 'running') {
                return $this->check('horizon', 'fail', 'A Horizon master supervisor is paused or unhealthy.');
            }
        }

        return $this->check('horizon', 'pass', 'Horizon has ' . count($masters) . ' running master supervisor(s).');
    }

    /** @return array{name: string, status: string, message: string} */
    private function schedulerCheck(SchedulerHeartbeat $heartbeat): array
    {
        try {
            $age = $heartbeat->ageSeconds();
            $isFresh = $heartbeat->isFresh();
        } catch (Throwable) {
            return $this->check('scheduler', 'fail', 'The scheduler heartbeat could not be read.');
        }

        if ($age === null) {
            return $this->check('scheduler', 'fail', 'No scheduler heartbeat has been recorded.');
        }

        if (! $isFresh) {
            return $this->check('scheduler', 'fail', 'The scheduler heartbeat is stale.');
        }

        return $this->check('scheduler', 'pass', "The scheduler heartbeat is {$age} seconds old.");
    }

    /** @return array{name: string, status: string, message: string} */
    private function queueDepthCheck(): array
    {
        $queueNames = LaravelCrawlQueue::allNames();
        if ($queueNames === []) {
            return $this->check('queue_depth', 'fail', 'No crawl queues are configured.');
        }

        $connectionName = (string) config('queue.default', 'database');
        $driver = (string) config("queue.connections.{$connectionName}.driver", $connectionName);
        if (! in_array($driver, ['database', 'redis', 'sync'], true)) {
            return $this->check('queue_depth', 'fail', 'Queue depth is unsupported for the configured queue driver.');
        }

        $depths = [];
        try {
            foreach ($queueNames as $queueName) {
                $depths[$queueName] = LaravelCrawlQueue::readyDepth($queueName);
            }
        } catch (Throwable) {
            return $this->check('queue_depth', 'fail', 'Queue depth could not be read.');
        }

        $fullQueues = array_filter($depths, static fn(int $depth, string $name): bool => $depth >= LaravelCrawlQueue::depthLimit($name), ARRAY_FILTER_USE_BOTH);
        $maximum = max($depths);
        if ($fullQueues !== []) {
            $names = implode(', ', array_keys($fullQueues));

            // A full queue means the crawl is busy and backpressure is working: a warning, not a failed deploy.
            return $this->check(
                'queue_depth',
                'warn',
                "Queue depth reached its configured limit for: {$names}.",
            );
        }

        return $this->check(
            'queue_depth',
            'pass',
            "All crawl queues are below their configured limits; maximum depth is {$maximum}.",
        );
    }

    /**
     * @return list<array{name: string, status: string, message: string}>
     */
    private function apiChecks(ApiKeyService $apiKeys): array
    {
        try {
            $temporaryKey = $apiKeys->create('JavMeta deployment smoke check');
        } catch (Throwable) {
            return [
                $this->check('api_lookup', 'fail', 'A temporary API key could not be created.'),
                $this->check('mcp_initialize', 'fail', 'MCP initialize could not be called without a temporary API key.'),
            ];
        }

        $apiLookup = $this->check('api_lookup', 'fail', 'The authenticated performer lookup failed.');
        $mcpInitialize = $this->check('mcp_initialize', 'fail', 'MCP initialize failed.');
        $cleanup = $this->check('temporary_api_key', 'pass', 'The temporary API key was revoked.');
        try {
            $baseUrl = rtrim((string) config('app.smoke_url', config('app.url', '')), '/');
            $host = (string) parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $apiLookup = $this->apiLookup($baseUrl, $host, $temporaryKey->plaintext);
            $mcpInitialize = $this->mcpInitialize($baseUrl, $host, $temporaryKey->plaintext);
        } catch (Throwable) {
            $apiLookup = $this->check('api_lookup', 'fail', 'The authenticated performer lookup failed.');
            $mcpInitialize = $this->check('mcp_initialize', 'fail', 'MCP initialize failed.');
        } finally {
            try {
                $apiKeys->revoke((int) $temporaryKey->model->getKey());
            } catch (Throwable) {
                $cleanup = $this->check('temporary_api_key', 'fail', 'The temporary API key could not be revoked.');
            }
        }

        return [$apiLookup, $mcpInitialize, $cleanup];
    }

    /** @return array{name: string, status: string, message: string} */
    private function apiLookup(string $baseUrl, string $host, string $key): array
    {
        try {
            $response = Http::connectTimeout(2)
                ->timeout(4)
                ->withHeader('Host', $host)
                ->withHeader('X-API-Key', $key)
                ->get($baseUrl . '/api/v1/performers?per_page=1');
            $payload = $response->json();

            if ($response->successful() && is_array($payload) && is_array($payload['data'] ?? null)) {
                return $this->check('api_lookup', 'pass', 'The authenticated performer lookup returned a valid response.');
            }
        } catch (Throwable $exception) {
            return $this->check(
                'api_lookup',
                'fail',
                'The authenticated performer lookup could not complete (' . class_basename($exception) . ').',
            );
        }

        return $this->check('api_lookup', 'fail', 'The authenticated performer lookup failed.');
    }

    /** @return array{name: string, status: string, message: string} */
    private function mcpInitialize(string $baseUrl, string $host, string $key): array
    {
        try {
            $response = Http::connectTimeout(2)
                ->timeout(4)
                ->withHeader('Host', $host)
                ->withHeader('X-API-Key', $key)
                ->post($baseUrl . '/api/v1/mcp', [
                    'jsonrpc' => '2.0',
                    'id' => 'smoke-check',
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2024-11-05',
                        'capabilities' => (object) [],
                        'clientInfo' => ['name' => 'javmeta-smoke', 'version' => '1.0'],
                    ],
                ]);

            if (
                $response->successful()
                && $response->json('result.protocolVersion') === '2024-11-05'
                && $response->json('result.serverInfo.name') === 'javmeta'
                && is_array($response->json('result.capabilities'))
            ) {
                return $this->check('mcp_initialize', 'pass', 'MCP initialize returned the JavMeta server identity.');
            }
        } catch (Throwable $exception) {
            return $this->check(
                'mcp_initialize',
                'fail',
                'MCP initialize could not complete (' . class_basename($exception) . ').',
            );
        }

        return $this->check('mcp_initialize', 'fail', 'MCP initialize failed.');
    }

    /** @return array{name: string, status: string, message: string} */
    private function sentryCheck(HubInterface $sentry): array
    {
        if (trim((string) config('sentry.dsn', '')) === '') {
            return $this->check('sentry', 'skipped', 'Sentry is disabled by configuration.');
        }

        try {
            $client = $sentry->getClient();
            if ($client === null) {
                return $this->check('sentry', 'fail', 'Sentry is enabled but its client is unavailable.');
            }

            $eventId = $sentry->captureMessage('JavMeta deployment smoke check', Severity::info());
            if ($eventId === null) {
                return $this->check('sentry', 'fail', 'Sentry did not accept the smoke-check event.');
            }

            $result = $client->flush(3);
            if ((string) $result->getStatus() !== 'SUCCESS') {
                return $this->check('sentry', 'fail', 'Sentry did not deliver the smoke-check event.');
            }
        } catch (Throwable) {
            return $this->check('sentry', 'fail', 'Sentry did not deliver the smoke-check event.');
        }

        return $this->check('sentry', 'pass', 'Sentry accepted and delivered the smoke-check event.');
    }
}

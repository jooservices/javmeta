<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Services\Search\ElasticsearchHttpClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use Throwable;

/**
 * Per-service health probes for monitoring (MCP `system_status`).
 * Probes are fail-open: a broken dependency is reported, never raised.
 */
final class SystemStatusService
{
    public function __construct(private readonly ElasticsearchHttpClient $elasticsearch) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $services = [
            'database' => $this->probeDatabase(),
            'elasticsearch' => $this->probeElasticsearch(),
            'embedder' => $this->probeEmbedder(),
            'activity_log' => $this->probeActivityLog(),
            'observability' => $this->probeOpenObserve(),
        ];

        $databaseOk = $services['database']['status'] === 'ok';
        $degraded = false;
        foreach ($services as $service) {
            if ($service['status'] === 'down') {
                $degraded = true;
            }
        }

        $status = $databaseOk ? ($degraded ? 'degraded' : 'ok') : 'down';

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'services' => $services,
        ];
    }

    /** @return array{status: string, detail?: string} */
    private function probeDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'detail' => $this->brief($exception)];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function probeElasticsearch(): array
    {
        try {
            $response = $this->elasticsearch->get('health', '/', 3);

            return $response->successful() ? ['status' => 'ok'] : ['status' => 'down'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'detail' => $this->brief($exception)];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function probeEmbedder(): array
    {
        $url = rtrim((string) config('elasticsearch.embedder_url', 'http://embedder:8000'), '/');

        try {
            $response = Http::timeout(3)->get($url . '/healthz');

            return $response->successful() ? ['status' => 'ok'] : ['status' => 'down'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'detail' => $this->brief($exception)];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function probeActivityLog(): array
    {
        $dsn = $this->mongoDsn();
        if ($dsn === null) {
            return ['status' => 'disabled'];
        }

        try {
            $manager = new Manager($dsn, ['serverSelectionTimeoutMS' => 1500]);
            $manager->executeCommand('admin', new Command(['ping' => 1]));

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'detail' => $this->brief($exception)];
        }
    }

    /** @return array{status: string, detail?: string} */
    private function probeOpenObserve(): array
    {
        if (! (bool) config('openobserve.enabled', false)) {
            return ['status' => 'disabled'];
        }

        $url = rtrim((string) config('openobserve.url', 'http://openobserve:5080'), '/');

        try {
            $response = Http::timeout(3)->get($url . '/api/health');

            return $response->successful() ? ['status' => 'ok'] : ['status' => 'down'];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'detail' => $this->brief($exception)];
        }
    }

    private function mongoDsn(): ?string
    {
        $dsn = (string) config('database.connections.mongodb.dsn', '');
        $host = (string) preg_replace('#^mongodb://([^:@/]+).*$#', '$1', $dsn);

        return $host === '' ? null : $dsn;
    }

    private function brief(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== '' ? mb_substr($message, 0, 200) : $exception::class;
    }
}

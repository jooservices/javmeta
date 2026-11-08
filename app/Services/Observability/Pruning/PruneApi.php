<?php

declare(strict_types=1);

namespace App\Services\Observability\Pruning;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Fail closed; never include response bodies or transport exceptions in diagnostics. */
final class PruneApi
{
    private bool $auditFailed = false;

    /** @param array<string, mixed> $data
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $this->assertAudited();
        $status = 0;
        try {
            $response = Http::baseUrl((string) config('openobserve.url'))
                ->withBasicAuth((string) config('openobserve.email'), (string) config('openobserve.password'))
                ->connectTimeout(5)->timeout(30)->acceptJson()->withoutRedirecting()
                ->send($method, $path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
            $status = $response->status();
        } catch (Throwable) {
            throw new RuntimeException('OpenObserve transport failed; response suppressed.');
        } finally {
            try {
                Log::channel('single')->info('OpenObserve prune API', ['method' => $method, 'path' => $path, 'status' => $status]);
            } catch (Throwable) {
                $this->auditFailed = true;
            }
        }
        // Let the caller record an accepted DELETE before stopping for an audit failure.
        if ($method !== 'DELETE') {
            $this->assertAudited();
        }
        if (! $response->successful()) {
            throw new RuntimeException("OpenObserve HTTP {$status}; response suppressed.");
        }
        if ($method === 'DELETE') {
            return [];
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Invalid OpenObserve JSON; response suppressed.');
        }

        return $body;
    }

    private function assertAudited(): void
    {
        if ($this->auditFailed) {
            throw new RuntimeException('API audit logging failed; stopping immediately.');
        }
    }

    public function orgPath(): string
    {
        return '/api/' . rawurlencode((string) config('openobserve.org'));
    }

    /** @return list<array<string, mixed>> */
    public function streams(): array
    {
        $body = $this->request('GET', $this->orgPath() . '/streams', ['fetchSchema' => 'false']);
        $list = $this->collection($body, 'list');
        if (! is_numeric($body['total'] ?? null) || (int) $body['total'] != $body['total'] || (int) $body['total'] !== count($list)) {
            throw new RuntimeException('Incomplete stream inventory.');
        }

        $seen = [];
        foreach ($list as $stream) {
            if (! is_string($stream['name'] ?? null) || ! is_string($stream['stream_type'] ?? null) || ! is_array($stream['stats'] ?? null)) {
                throw new RuntimeException('Invalid stream identity or statistics.');
            }
            $identity = $stream['stream_type'] . ':' . $stream['name'];
            if (isset($seen[$identity])) {
                throw new RuntimeException('Duplicate stream identity in inventory.');
            }
            $seen[$identity] = true;
        }

        return $list;
    }

    /** @param array<mixed> $body
     * @return list<array<string, mixed>>
     */
    public function collection(array $body, string $key): array
    {
        $list = $body[$key] ?? null;
        if (! is_array($list) || ! array_is_list($list) || array_filter($list, 'is_array') !== $list) {
            throw new RuntimeException('Cannot check reference collection; unexpected response shape.');
        }
        if (array_key_exists('total', $body) && (! is_numeric($body['total']) || (int) $body['total'] != $body['total'] || (int) $body['total'] !== count($list))) {
            throw new RuntimeException('Cannot check incomplete reference collection.');
        }

        return $list;
    }
}

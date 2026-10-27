<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ApiUsageLog;
use App\Observability\OpenObserveClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class EmitObservabilityLog implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(public readonly array $record)
    {
        $this->onQueue((string) config('javmeta_queues.observability_queue', 'observability'));
    }

    public function handle(OpenObserveClient $client): void
    {
        if (($this->record['event'] ?? null) === 'api_usage') {
            ApiUsageLog::query()->create([
                'api_key_id' => isset($this->record['api_key_id']) ? (int) $this->record['api_key_id'] : null,
                'endpoint' => (string) ($this->record['endpoint'] ?? ''),
                'method' => (string) ($this->record['method'] ?? ''),
                'status_code' => (int) ($this->record['status_code'] ?? 0),
                'response_ms' => max(0, (int) ($this->record['response_ms'] ?? 0)),
                'ip_hash' => (string) ($this->record['ip_hash'] ?? ''),
                'created_at' => now(),
            ]);
        }

        $client->ingestLogs([$this->record]);
    }
}

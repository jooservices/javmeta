<?php

declare(strict_types=1);

namespace App\Jobs;

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
        $client->ingestLogs([$this->record]);
    }
}

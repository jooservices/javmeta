<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Performer;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class IndexPerformerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $performerId) {}

    public function uniqueId(): string
    {
        return (string) $this->performerId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ElasticsearchIndexer $indexer): void
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return;
        }

        $performer = Performer::query()->find($this->performerId);
        if (! $performer instanceof Performer) {
            return;
        }

        if (! $indexer->indexPerformer($performer)) {
            throw new RuntimeException("Performer indexing failed for performer [{$this->performerId}].");
        }
    }
}

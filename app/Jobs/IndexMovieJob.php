<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Movie;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class IndexMovieJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $movieId) {}

    public function uniqueId(): string
    {
        return (string) $this->movieId;
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

        $movie = Movie::query()->find($this->movieId);
        if (! $movie instanceof Movie) {
            return;
        }

        if (! $indexer->indexMovie($movie)) {
            throw new RuntimeException("Movie indexing failed for movie [{$this->movieId}].");
        }
    }
}

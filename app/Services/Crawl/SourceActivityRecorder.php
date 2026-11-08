<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Source;
use App\Repositories\SourceRepository;

/**
 * Records the last crawl success / failure per source for status and the
 * stale-source watchdog. Fetch health (soft 404, blocking, retries) is owned
 * by crawlerx; javmeta keeps no circuit breaker of its own.
 */
final class SourceActivityRecorder
{
    public function __construct(private readonly ?SourceRepository $sources = null) {}

    public function recordSuccess(Source|string $source): void
    {
        $this->repository()->updateSource($this->slug($source), [
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    public function recordFailure(Source|string $source, string $error): void
    {
        $this->repository()->updateSource($this->slug($source), [
            'last_error_at' => now(),
            'last_error' => $error,
        ]);
    }

    private function slug(Source|string $source): string
    {
        return $source instanceof Source ? $source->slug : $source;
    }

    private function repository(): SourceRepository
    {
        return $this->sources ?? app(SourceRepository::class);
    }
}

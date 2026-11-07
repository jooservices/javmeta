<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\Source;
use App\Repositories\SourceRepository;

/**
 * Records the last crawl success / failure per source for status and the
 * stale-source watchdog and records consecutive remote block responses so
 * dispatch pauses a source while its upstream cools down.
 */
final class SourceActivityRecorder
{
    public function __construct(private readonly ?SourceRepository $sources = null) {}

    public function recordSuccess(Source|string $source): void
    {
        $slug = $this->slug($source);
        app(SourceCircuitBreaker::class)->recordSuccess($slug);
        $this->repository()->updateSource($slug, [
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    public function recordFailure(Source|string $source, string $error, ?CrawlErrorCode $errorCode = null): void
    {
        $slug = $this->slug($source);
        app(SourceCircuitBreaker::class)->recordFailure($slug, $errorCode ?? CrawlErrorCode::Unknown);
        $this->repository()->updateSource($slug, [
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

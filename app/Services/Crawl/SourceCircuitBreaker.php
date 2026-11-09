<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Events\SourceCircuitOpened;
use App\Listeners\SendSourceCircuitAlert;
use App\Repositories\SourceCircuitBreakerRepository;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Support\Facades\Event;

final class SourceCircuitBreaker
{
    private const BLOCK_CODES = [
        CrawlErrorCode::Blocked,
        CrawlErrorCode::RateLimited,
        CrawlErrorCode::Challenge,
    ];

    public function __construct(
        private readonly SourceCircuitBreakerRepository $sources,
        private readonly AlertDispatcher $alerts,
    ) {}

    public function recordFailure(string $sourceSlug, CrawlErrorCode $errorCode): void
    {
        if (! in_array($errorCode, self::BLOCK_CODES, true)) {
            $this->sources->resetFailures($sourceSlug);

            return;
        }

        $state = $this->sources->recordBlock(
            $sourceSlug,
            (int) config('javmeta_sources.defaults.circuit.failure_threshold', 3),
            (int) config('javmeta_sources.defaults.circuit.cooldown_seconds', 300),
            (int) config('javmeta_sources.defaults.circuit.max_cooldown_seconds', 3600),
        );
        if ($state === null || ! $state['opened'] || $state['resume_at'] === null || $state['cooldown_seconds'] === null) {
            return;
        }

        Event::dispatch(new SourceCircuitOpened(
            $sourceSlug,
            $state['consecutive_failures'],
            $state['cooldown_seconds'],
            $state['resume_at'],
            $state['open_count'],
        ));
    }

    public function recordSuccess(string $sourceSlug): void
    {
        $openCount = $this->sources->reset($sourceSlug);
        if ($openCount !== null) {
            $this->resolveAlert($sourceSlug, $openCount);
        }
    }

    /** @return list<string> */
    public function pausedSourceSlugs(): array
    {
        $state = $this->sources->syncPaused();
        foreach ($state['closed'] as $closed) {
            $this->resolveAlert($closed['slug'], $closed['open_count']);
        }

        return $state['paused'];
    }

    /** @phpstan-impure The circuit state lives in the database and can change between two calls. */
    public function isPaused(string $sourceSlug): bool
    {
        return in_array($sourceSlug, $this->pausedSourceSlugs(), true);
    }

    private function resolveAlert(string $sourceSlug, int $openCount): void
    {
        $this->alerts->resolveTemplate(
            SendSourceCircuitAlert::causeKey($sourceSlug, $openCount),
            'crawl.source_circuit_recovered',
            ['source_slug' => $sourceSlug],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Downloads;

use App\Models\Source;
use App\Services\Crawl\SourceRequestPacer;
use App\Services\Crawl\SourceThrottle;

/**
 * Thin, mockable gate around the final SourceRequestPacer for on-demand
 * download-link searches (keeps unit tests off Redis while production still
 * honours the shared egress gap).
 */
class DownloadSearchPacer
{
    /** @var callable(string, float, string, int): (?int)|null */
    private $reserveFn;

    /** @var callable(int): void|null */
    private $waitFn;

    public function __construct(
        private readonly SourceRequestPacer $pacer,
        private readonly SourceThrottle $throttle,
        ?callable $reserveFn = null,
        ?callable $waitFn = null,
    ) {
        $this->reserveFn = $reserveFn;
        $this->waitFn = $waitFn;
    }

    /**
     * Reserve a polite start slot. Returns an error code when the source is
     * too busy within the configured timeout; null when the caller may fetch.
     */
    public function beforeFetch(Source $source, string $sourceSlug, string $lane, int $timeoutSeconds): ?string
    {
        $gap = $this->throttle->currentGap($source);
        $reserve = $this->reserveFn ?? [$this->pacer, 'reserveStart'];
        $wait = $this->waitFn ?? [$this->pacer, 'wait'];
        $slot = $reserve(
            $sourceSlug,
            $gap,
            $lane,
            max(1_000_000, $timeoutSeconds * 1_000_000),
        );
        if ($slot === null) {
            return 'source_busy';
        }
        if ($slot > 0) {
            $wait($slot);
        }

        return null;
    }
}

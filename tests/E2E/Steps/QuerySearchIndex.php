<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Closure;
use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\StepFailure;

/** Queries the Elasticsearch movie index (text or kNN with the stub embedder) and expects the crawled movie. */
final class QuerySearchIndex implements Step
{
    public const TEXT = 'text';
    public const SEMANTIC = 'semantic';

    /** @param Closure(string, string): list<string> $search Returns the display codes the index found for a mode and query. */
    public function __construct(private readonly Closure $search, private readonly string $mode, private readonly ?string $query = null) {}

    public function run(Context $context): Context
    {
        $crawl = $context->crawl;
        if ($crawl === null) {
            throw new StepFailure('No fixture crawl is available to search for.', $context);
        }

        $codes = ($this->search)($this->mode, $this->query ?? $crawl->movieTitle);
        if (! in_array($crawl->movieCode, $codes, true)) {
            throw new StepFailure("The {$this->mode} index search did not return the crawled movie.", $context);
        }

        return $context;
    }
}

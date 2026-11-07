<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MovieSource;
use App\Data\Crawl\MovieDraft;
use Carbon\CarbonImmutable;

/** Persistence operations for per-source movie provenance. */
final class MovieSourceRepository extends BusinessQueryRepository
{
    public function __construct(MovieSource $model)
    {
        parent::__construct($model);
    }

    public function recordDraft(int $movieId, MovieDraft $draft, ?string $mongoId): void
    {
        $this->newQuery()->updateOrCreate(
            ['movie_id' => $movieId, 'site_slug' => $draft->sourceSlug],
            [
                'mongo_id' => $mongoId,
                'source_url' => $draft->sourceUrl,
                'source_code' => $draft->code,
                'crawled_at' => $draft->crawledAt ?? CarbonImmutable::now(),
            ],
        );
    }
}

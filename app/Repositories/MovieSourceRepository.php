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
        $source = $this->newQuery()
            ->where(['movie_id' => $movieId, 'site_slug' => $draft->sourceSlug])
            ->lockForUpdate()
            ->first();

        if ($mongoId === null && $source instanceof MovieSource && $source->source_url === $draft->sourceUrl) {
            $mongoId = $source->mongo_id;
        }

        if ($mongoId !== null) {
            $previousSource = $this->newQuery()
                ->where('site_slug', $draft->sourceSlug)
                ->where('mongo_id', $mongoId)
                ->where('movie_id', '!=', $movieId)
                ->lockForUpdate()
                ->first();

            $previousSource?->forceFill(['mongo_id' => null])->save();
        }

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

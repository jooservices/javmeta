<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\BackfillEmbeddingMetrics;
use PHPUnit\Framework\TestCase;

final class BackfillEmbeddingMetricsTest extends TestCase
{
    public function test_points_report_missing_and_fixed_counts_per_entity(): void
    {
        self::assertSame([
            [
                'name' => 'javmeta_search_embedding_backfill_missing',
                'value' => 3,
                'labels' => ['entity' => 'movies'],
            ],
            [
                'name' => 'javmeta_search_embedding_backfill_fixed',
                'value' => 2,
                'labels' => ['entity' => 'movies'],
            ],
            [
                'name' => 'javmeta_search_embedding_backfill_missing',
                'value' => 1,
                'labels' => ['entity' => 'performers'],
            ],
            [
                'name' => 'javmeta_search_embedding_backfill_fixed',
                'value' => 1,
                'labels' => ['entity' => 'performers'],
            ],
        ], BackfillEmbeddingMetrics::points(
            ['movies' => 3, 'performers' => 1],
            ['movies' => 2, 'performers' => 1],
        ));
    }

    public function test_missing_fixed_entry_defaults_to_zero(): void
    {
        self::assertSame([
            [
                'name' => 'javmeta_search_embedding_backfill_missing',
                'value' => 0,
                'labels' => ['entity' => 'movies'],
            ],
            [
                'name' => 'javmeta_search_embedding_backfill_fixed',
                'value' => 0,
                'labels' => ['entity' => 'movies'],
            ],
        ], BackfillEmbeddingMetrics::points(['movies' => 0], []));
    }
}

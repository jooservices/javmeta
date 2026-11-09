<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use Tests\TestCase;

final class SearchEvalFixtureTest extends TestCase
{
    public function test_fixture_has_fifty_queries_with_the_required_language_split(): void
    {
        $path = base_path('tests/Fixtures/search-eval.json');
        $queries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(50, $queries);
        self::assertSame(['en' => 20, 'vi' => 15, 'ja' => 15], array_count_values(array_column($queries, 'lang')));
        foreach ($queries as $query) {
            self::assertArrayHasKey('q', $query);
            self::assertArrayHasKey('genres', $query);
            self::assertArrayHasKey('performers', $query);
            self::assertNotSame([], $query['genres'] + $query['performers']);
        }
    }
}

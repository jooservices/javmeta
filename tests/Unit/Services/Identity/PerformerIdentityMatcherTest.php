<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Identity;

use App\Services\Identity\PerformerIdentityMatcher;
use Tests\TestCase;

final class PerformerIdentityMatcherTest extends TestCase
{
    public function test_fixture_signals_merge_only_on_strong_matches_and_report_ambiguous_names(): void
    {
        $fixtures = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/performer-identity/matches.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $matcher = new PerformerIdentityMatcher();

        foreach ($fixtures as $fixture) {
            $result = $matcher->evaluate($fixture['incoming'], $fixture['candidates']);

            self::assertSame($fixture['expected'], $result['decision'], $fixture['case']);
        }
    }

    public function test_cross_link_namespace_must_match_exactly(): void
    {
        $result = (new PerformerIdentityMatcher())->evaluate(
            [
                'id' => 2,
                'names' => ['Mina Hope'],
                'birth_dates' => [],
                'cross_links' => [['source' => 'catalog-a', 'id' => '42']],
            ],
            [[
                'id' => 1,
                'names' => ['Ami Natsuki'],
                'birth_dates' => [],
                'cross_links' => [['source' => 'catalog-b', 'id' => '42']],
            ]],
        );

        self::assertSame('none', $result['decision']);
    }

    public function test_every_ambiguous_candidate_is_reported_from_a_large_candidate_stream(): void
    {
        $incoming = ['id' => 1, 'names' => ['yamada abcdefghij'], 'birth_dates' => [], 'cross_links' => []];
        $candidates = (static function (): \Generator {
            for ($id = 2; $id <= 20_001; $id++) {
                yield ['id' => $id, 'names' => ['yamada abcdefghi' . ($id % 7 === 0 ? 'j' : 'k')], 'birth_dates' => [], 'cross_links' => []];
            }
        })();

        gc_collect_cycles();
        $before = memory_get_peak_usage();
        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('review', $result['decision']);
        self::assertCount(20_000, $result['review_candidates']);
        self::assertSame(1.0, $result['review_candidates'][0]['similarity']);
        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function test_strong_matches_are_reported_before_weaker_reviews(): void
    {
        $candidate = static fn(int $id, string $birth): array => [
            'id' => $id,
            'names' => ['Mina Hope'],
            'birth_dates' => [$birth],
            'cross_links' => [],
        ];

        $result = (new PerformerIdentityMatcher())->evaluate(
            ['id' => 9, 'names' => ['Mina Hope'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []],
            [$candidate(1, '1991-02-02'), $candidate(2, '1992-03-03'), $candidate(3, '1990-01-01'), $candidate(4, '1990-01-01')],
        );

        self::assertSame('review', $result['decision']);
        self::assertSame([3, 4, 1, 2], array_column($result['review_candidates'], 'id'));
        self::assertSame(['multiple_strong_matches', 'multiple_strong_matches', 'birth_date_missing_or_conflicting', 'birth_date_missing_or_conflicting'], array_column($result['review_candidates'], 'reason'));
    }

    public function test_strong_candidates_are_ranked_by_similarity(): void
    {
        $link = [['source' => 'x', 'id' => '9']];
        $incoming = ['id' => 10, 'names' => ['alpha beta'], 'birth_dates' => [], 'cross_links' => $link];
        $candidates = [
            ['id' => 1, 'names' => ['gamma delta'], 'birth_dates' => [], 'cross_links' => $link],
            ['id' => 2, 'names' => ['gamma delta'], 'birth_dates' => [], 'cross_links' => $link],
            ['id' => 3, 'names' => ['beta alpha'], 'birth_dates' => [], 'cross_links' => $link],
        ];

        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('review', $result['decision']);
        self::assertSame([3, 1, 2], array_column($result['review_candidates'], 'id'));
    }

    public function test_many_strong_matches_are_all_reported_for_review(): void
    {
        $incoming = ['id' => 1, 'names' => ['a b'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []];
        $candidates = array_map(
            static fn(int $id): array => ['id' => $id, 'names' => ['b a'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []],
            range(2, 40),
        );

        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('review', $result['decision']);
        self::assertNull($result['candidate_id']);
        self::assertCount(39, $result['review_candidates']);
    }
}

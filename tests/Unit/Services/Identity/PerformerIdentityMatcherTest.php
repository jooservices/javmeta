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

    public function test_candidate_state_stays_bounded_for_a_huge_candidate_stream(): void
    {
        config()->set('performer_identity.max_candidates_per_source', 20);
        $incoming = ['id' => 1, 'names' => ['yamada abcdefghij'], 'birth_dates' => [], 'cross_links' => []];
        $candidates = (static function (): \Generator {
            for ($id = 2; $id <= 100_001; $id++) {
                yield ['id' => $id, 'names' => ['yamada abcdefghi' . ($id % 7 === 0 ? 'j' : 'k')], 'birth_dates' => [], 'cross_links' => []];
            }
        })();

        gc_collect_cycles();
        $before = memory_get_peak_usage();
        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('review', $result['decision']);
        self::assertCount(20, $result['review_candidates']);
        self::assertSame(1.0, $result['review_candidates'][0]['similarity']);
        self::assertLessThan(4 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function test_strong_matches_are_reported_even_when_weaker_reviews_fill_the_list(): void
    {
        config()->set('performer_identity.max_candidates_per_source', 2);
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
        self::assertSame([3, 4], array_column($result['review_candidates'], 'id'));
        self::assertSame(['multiple_strong_matches'], array_unique(array_column($result['review_candidates'], 'reason')));
    }

    public function test_a_later_higher_similarity_strong_candidate_is_not_dropped_by_the_cap(): void
    {
        config()->set('performer_identity.max_candidates_per_source', 2);
        $link = [['source' => 'x', 'id' => '9']];
        $incoming = ['id' => 10, 'names' => ['alpha beta'], 'birth_dates' => [], 'cross_links' => $link];
        $candidates = [
            ['id' => 1, 'names' => ['gamma delta'], 'birth_dates' => [], 'cross_links' => $link],
            ['id' => 2, 'names' => ['gamma delta'], 'birth_dates' => [], 'cross_links' => $link],
            ['id' => 3, 'names' => ['beta alpha'], 'birth_dates' => [], 'cross_links' => $link],
        ];

        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('review', $result['decision']);
        self::assertSame([3, 1], array_column($result['review_candidates'], 'id'));
    }

    public function test_many_strong_matches_are_counted_but_only_the_limit_is_reported(): void
    {
        config()->set('performer_identity.max_candidates_per_source', 3);
        $incoming = ['id' => 1, 'names' => ['a b'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []];
        $candidates = array_map(
            static fn(int $id): array => ['id' => $id, 'names' => ['b a'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []],
            range(2, 40),
        );

        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('review', $result['decision']);
        self::assertNull($result['candidate_id']);
        self::assertCount(3, $result['review_candidates']);
    }
}

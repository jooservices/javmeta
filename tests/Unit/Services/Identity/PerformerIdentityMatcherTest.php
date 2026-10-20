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

    public function test_review_memory_does_not_grow_with_the_candidate_count(): void
    {
        $incoming = ['id' => 1, 'names' => ['yamada abcdefghij'], 'birth_dates' => [], 'cross_links' => []];
        $run = static function (int $total) use ($incoming): array {
            $factory = static function () use ($total): \Generator {
                for ($id = 2; $id <= $total + 1; $id++) {
                    yield ['id' => $id, 'names' => ['yamada abcdefghi' . ($id % 7 === 0 ? 'j' : 'k')], 'birth_dates' => [], 'cross_links' => []];
                }
            };

            gc_collect_cycles();
            memory_reset_peak_usage();
            $base = memory_get_usage();
            $result = (new PerformerIdentityMatcher())->evaluate($incoming, $factory);
            $count = 0;
            foreach ($result['review_candidates'] as $review) {
                $count += $review['id'] > 0 ? 1 : 0;
            }

            return [$result['decision'], $count, memory_get_peak_usage() - $base];
        };

        [$decision, $small, $smallPeak] = $run(2_000);
        [, $large, $largePeak] = $run(20_000);

        self::assertSame('review', $decision);
        self::assertSame(2_000, $small);
        self::assertSame(20_000, $large);
        // Ten times the candidates must not need meaningfully more memory.
        self::assertLessThan($smallPeak + 1024 * 1024, $largePeak);
    }

    public function test_a_single_strong_match_still_reports_the_ambiguous_candidates(): void
    {
        $incoming = ['id' => 9, 'names' => ['Mina Hope'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []];
        $candidates = [
            ['id' => 1, 'names' => ['Mina Hope'], 'birth_dates' => ['1991-02-02'], 'cross_links' => []],
            ['id' => 2, 'names' => ['Mina Hope'], 'birth_dates' => ['1990-01-01'], 'cross_links' => []],
            ['id' => 3, 'names' => ['Mina Hop'], 'birth_dates' => [], 'cross_links' => []],
        ];

        $result = (new PerformerIdentityMatcher())->evaluate($incoming, $candidates);

        self::assertSame('merge', $result['decision']);
        self::assertSame(2, $result['candidate_id']);
        $reviews = iterator_to_array($result['review_candidates'], false);
        self::assertSame([1, 3], array_column($reviews, 'id'));
        self::assertSame(['birth_date_missing_or_conflicting', 'weak_name_match'], array_column($reviews, 'reason'));
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
        $reviews = iterator_to_array($result['review_candidates'], false);
        self::assertSame([3, 4, 1, 2], array_column($reviews, 'id'));
        self::assertSame(['multiple_strong_matches', 'multiple_strong_matches', 'birth_date_missing_or_conflicting', 'birth_date_missing_or_conflicting'], array_column($reviews, 'reason'));
    }

    public function test_strong_candidates_are_reported_in_candidate_order(): void
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
        self::assertSame([1, 2, 3], array_column(iterator_to_array($result['review_candidates'], false), 'id'));
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
        self::assertCount(39, iterator_to_array($result['review_candidates'], false));
    }
}

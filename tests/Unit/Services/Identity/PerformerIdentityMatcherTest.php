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
}

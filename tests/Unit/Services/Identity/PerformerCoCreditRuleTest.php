<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerCoCreditRule;
use App\Services\Identity\PerformerIdentityMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PerformerCoCreditRuleTest extends TestCase
{
    #[DataProvider('names')]
    public function test_only_normalized_full_names_are_eligible(string $name, array $expected): void
    {
        $source = new PerformerSource(['name_romaji' => $name]);
        $source->setRelation('sourcePerformer', new Performer(['name_romaji' => $name]));
        $rule = new PerformerCoCreditRule(new PerformerIdentityMatcher());
        self::assertSame($expected, $rule->fullNames($source));
    }

    public static function names(): array
    {
        return [
            [' ＳＡＫＵＲＡ  Aoi ', ['aoi sakura']],
            ['Aoi-Sakura', ['aoi sakura']],
            ['Aika', []],
            ['Ami', []],
            ['Arisa', []],
            ['あいか', []],
            ['', []],
        ];
    }
}

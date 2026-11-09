<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Catalog\NameScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameScriptTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'latin words' => ['Big Tits', 'latin'],
            'digits first' => ['4HR+', 'latin'],
            'vietnamese letters' => ['Học đường', 'latin'],
            'punctuation then latin' => ['(Special) Edition', 'latin'],
            'kana' => ['ハイビジョン', 'ja'],
            'kanji with kana' => ['中出し', 'ja'],
            'kana wins over latin' => ['S1 ドラマ', 'ja'],
            'han only' => ['巨乳', 'zh'],
            'han wins over latin' => ['S1 巨乳', 'zh'],
            'symbols only' => ['★★★', 'other'],
            'cyrillic' => ['Привет', 'other'],
        ];
    }

    #[DataProvider('names')]
    public function test_it_names_the_writing_system(string $name, string $script): void
    {
        $this->assertSame($script, NameScript::classify($name));
        $this->assertTrue(NameScript::matches($name, $script));
        $this->assertTrue(NameScript::matches($name, 'all'));
        $this->assertFalse(NameScript::matches($name, $script === 'other' ? 'latin' : 'other'));
    }
}

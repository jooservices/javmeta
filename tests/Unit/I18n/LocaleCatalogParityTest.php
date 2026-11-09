<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LocaleCatalogParityTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function phpLangFiles(): array
    {
        $enDir = dirname(__DIR__, 3) . '/lang/en';
        $files = [];
        foreach (scandir($enDir) ?: [] as $file) {
            if (str_ends_with($file, '.php')) {
                $files[] = [$file];
            }
        }

        return $files;
    }

    #[DataProvider('phpLangFiles')]
    public function test_en_and_vi_php_catalogs_share_keys_placeholders_and_non_empty_values(string $file): void
    {
        $root = dirname(__DIR__, 3);
        $enPath = $root . '/lang/en/' . $file;
        $viPath = $root . '/lang/vi/' . $file;
        $this->assertFileExists($viPath, "Missing Vietnamese twin for {$file}");

        $en = $this->flatten((array) require $enPath);
        $vi = $this->flatten((array) require $viPath);

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($vi))), "{$file}: keys only in en");
        $this->assertSame([], array_values(array_diff(array_keys($vi), array_keys($en))), "{$file}: keys only in vi");

        foreach ($en as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "{$file} en empty: {$key}");
            $this->assertNotSame('', trim((string) $vi[$key]), "{$file} vi empty: {$key}");
            $this->assertSame(
                $this->placeholders((string) $value),
                $this->placeholders((string) $vi[$key]),
                "{$file} placeholder mismatch for {$key}",
            );
        }
    }

    public function test_js_catalogs_share_keys_placeholders_and_non_empty_values(): void
    {
        $root = dirname(__DIR__, 3);
        $en = json_decode((string) file_get_contents($root . '/public/ui/i18n/en.json'), true, 512, JSON_THROW_ON_ERROR);
        $vi = json_decode((string) file_get_contents($root . '/public/ui/i18n/vi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($en);
        $this->assertIsArray($vi);

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($vi))), 'JS keys only in en');
        $this->assertSame([], array_values(array_diff(array_keys($vi), array_keys($en))), 'JS keys only in vi');

        foreach ($en as $key => $value) {
            $this->assertCatalogValue($key, $value, 'en');
            $this->assertCatalogValue($key, $vi[$key], 'vi');
            $this->assertSame(
                $this->placeholders($this->asString($value)),
                $this->placeholders($this->asString($vi[$key])),
                "JS placeholder mismatch for {$key}",
            );
        }
    }

    private function assertCatalogValue(string $key, mixed $value, string $locale): void
    {
        if (is_array($value)) {
            $this->assertNotSame([], $value, "{$locale} empty plural object: {$key}");
            foreach ($value as $form => $text) {
                $this->assertContains($form, ['one', 'other', 'zero', 'few', 'many', 'two'], "{$locale} bad plural form {$form} on {$key}");
                $this->assertNotSame('', trim((string) $text), "{$locale} empty plural {$form} on {$key}");
            }

            return;
        }
        $this->assertIsString($value);
        $this->assertNotSame('', trim($value), "{$locale} empty: {$key}");
    }

    /** @param array<string, mixed> $tree
     *  @return array<string, string|array<string, string>>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $this->isAssoc($value) && ! $this->isPluralForms($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /** @param array<mixed, mixed> $value */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /** @param array<mixed, mixed> $value */
    private function isPluralForms(array $value): bool
    {
        $keys = array_keys($value);

        return $keys !== [] && count(array_diff($keys, ['one', 'other', 'zero', 'few', 'many', 'two'])) === 0;
    }

    private function asString(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(strval(...), $value));
        }

        return (string) $value;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}|:([a-zA-Z_][a-zA-Z0-9_]*)/', $text, $matches);

        $names = array_values(array_unique(array_filter([...$matches[1], ...$matches[2]])));
        sort($names);

        return $names;
    }
}

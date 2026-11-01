<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Illuminate\Support\Arr;
use Tests\TestCase;

final class SettingsRegistryTest extends TestCase
{
    public function test_every_registered_setting_has_a_default_type_range_and_vietnamese_copy(): void
    {
        $schema = config('javmeta_settings');
        $this->assertIsArray($schema['groups'] ?? null);
        $this->assertIsArray($schema['definitions'] ?? null);

        foreach ($schema['definitions'] as $key => $definition) {
            $this->assertArrayHasKey($definition['group'], $schema['groups'], $key);
            $this->assertNotSame('', trim((string) ($definition['label'] ?? '')), $key . ' label');
            $this->assertNotSame('', trim((string) ($definition['description'] ?? '')), $key . ' description');
            $this->assertMatchesRegularExpression('/[ăâđêôơưàáảãạằắẳẵặầấẩẫậèéẻẽẹềếểễệìíỉĩịòóỏõọồốổỗộờớởỡợùúủũụừứửữựỳýỷỹỵ]/iu', $definition['description'], $key . ' Vietnamese description');
            $this->assertContains($definition['type'], ['bool', 'int', 'float', 'string', 'secret', 'int_list', 'string_list', 'json'], $key . ' type');
            $this->assertNotEmpty($definition['config'] ?? [], $key . ' config mapping');

            foreach ($definition['config'] as $configKey) {
                $this->assertTrue(Arr::has(config()->all(), $configKey), $key . ' default missing at ' . $configKey);
            }

            if (in_array($definition['type'], ['int', 'float'], true)) {
                $this->assertArrayHasKey('min', $definition, $key . ' minimum');
                $this->assertArrayHasKey('max', $definition, $key . ' maximum');
                $this->assertLessThanOrEqual($definition['max'], $definition['min'], $key . ' range');
            } elseif ($definition['type'] === 'json') {
                $this->assertNotEmpty($definition['shape'] ?? [], $key . ' JSON shape');
            } elseif (in_array($definition['type'], ['string', 'secret', 'int_list', 'string_list'], true)) {
                $this->assertGreaterThan(0, $schema['validation']['max_text_length'] ?? 0, $key . ' text range');
                $this->assertGreaterThan(0, $schema['validation']['max_list_items'] ?? 0, $key . ' list range');
            }
        }
    }
}

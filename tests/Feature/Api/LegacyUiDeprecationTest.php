<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/** The legacy UI bridges stay as they are and only announce their successor. */
final class LegacyUiDeprecationTest extends TestCase
{
    public function test_a_legacy_ui_path_carries_the_deprecation_and_successor_headers_and_no_invented_sunset(): void
    {
        $response = $this->get('/ui-api/status/summary');

        $response->assertHeader('Deprecation', 'true');
        self::assertStringContainsString('rel="successor-version"', (string) $response->headers->get('Link'));
        self::assertNull($response->headers->get('Sunset'));
    }

    public function test_the_sunset_header_appears_once_a_date_is_configured(): void
    {
        config(['javmeta.legacy_ui_sunset' => 'Wed, 30 Jun 2027 00:00:00 GMT']);

        $this->get('/ui-api/status/summary')->assertHeader('Sunset', 'Wed, 30 Jun 2027 00:00:00 GMT')->assertHeader('Deprecation', 'true');
    }

    public function test_other_paths_are_untouched(): void
    {
        $response = $this->get('/up');

        self::assertNull($response->headers->get('Deprecation'));
        self::assertNull($response->headers->get('Sunset'));
    }

    public function test_the_machine_contracts_are_not_marked(): void
    {
        foreach (['/node/join.sh', '/files/download'] as $path) {
            self::assertNull($this->get($path)->headers->get('Deprecation'), $path);
        }
    }
}

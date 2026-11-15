<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ExpireLegacyEgressMetadataCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
    }

    public function test_explicit_legacy_node_and_removed_browser_metadata_get_a_bounded_lifetime(): void
    {
        $node = 'retired-xcrawler-node';
        $host = 'removed-browser.test';
        $nodeMeta = ['last_known_ip' => '198.51.100.10', 'change_count' => 3];
        $browserMeta = ['last_known_ip' => '198.51.100.11', 'change_count' => 2];
        Cache::forever('javmeta:egress-meta:' . $node, $nodeMeta);
        Cache::forever('javmeta:browser-egress-meta:' . $host, $browserMeta);

        $this->artisan('observability:expire-legacy-egress-meta', [
            'identities' => ['node:' . $node, 'browser:' . $host],
        ])->assertSuccessful();

        self::assertSame($nodeMeta, Cache::get('javmeta:egress-meta:' . $node));
        self::assertSame($browserMeta, Cache::get('javmeta:browser-egress-meta:' . $host));
        $this->travel(31)->days();
        self::assertNull(Cache::get('javmeta:egress-meta:' . $node));
        self::assertNull(Cache::get('javmeta:browser-egress-meta:' . $host));
    }

    public function test_legacy_expiry_refuses_a_batch_over_the_safe_limit_before_writing(): void
    {
        $identities = array_map(static fn(int $index): string => 'node:retired-' . $index, range(1, 201));
        Cache::forever('javmeta:egress-meta:retired-1', ['change_count' => 1]);

        $this->artisan('observability:expire-legacy-egress-meta', ['identities' => $identities])
            ->assertFailed();

        self::assertSame(['change_count' => 1], Cache::get('javmeta:egress-meta:retired-1'));
    }
}

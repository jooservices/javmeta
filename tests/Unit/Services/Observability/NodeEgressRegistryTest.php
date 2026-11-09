<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Observability;

use App\Services\Observability\NodeEgressProbe;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

final class NodeEgressRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
    }

    public function test_egress_node_index_is_capped_at_200_and_expires(): void
    {
        $probe = app(NodeEgressProbe::class);
        $rememberNode = new ReflectionMethod(NodeEgressProbe::class, 'rememberNode');
        $rememberNode->setAccessible(true);

        for ($index = 0; $index < 300; $index++) {
            $rememberNode->invoke($probe, 'egress-node-' . $index);
        }

        $nodes = Cache::get('javmeta:egress:nodes');
        self::assertIsArray($nodes);
        self::assertCount(200, $nodes);
        self::assertNotContains('egress-node-0', $nodes);
        self::assertContains('egress-node-299', $nodes);

        $rememberNode->invoke($probe, 'egress-node-0');
        $nodes = Cache::get('javmeta:egress:nodes');
        self::assertIsArray($nodes);
        self::assertCount(200, $nodes);
        self::assertContains('egress-node-0', $nodes);
        self::assertNotContains('egress-node-100', $nodes);

        $this->travel(8)->days();

        self::assertNull(Cache::get('javmeta:egress:nodes'));
    }

    public function test_egress_meta_keys_expire_after_30_days(): void
    {
        $probe = app(NodeEgressProbe::class);
        $storeResult = new ReflectionMethod(NodeEgressProbe::class, 'storeResult');
        $storeResult->setAccessible(true);
        $storeResult->invoke($probe, 'node', 'egress-meta-test-node', null, 120);

        self::assertIsArray(Cache::get('javmeta:egress-meta:egress-meta-test-node'));

        $this->travel(31)->days();

        self::assertNull(Cache::get('javmeta:egress-meta:egress-meta-test-node'));
    }
}

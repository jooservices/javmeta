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

    public function test_retired_node_metadata_from_the_legacy_index_gets_a_bounded_lifetime(): void
    {
        $retired = 'retired-worker-before-cap';
        $metadata = ['last_known_ip' => '198.51.100.19', 'change_count' => 4];
        Cache::forever('javmeta:egress:nodes', [$retired]);
        Cache::forever('javmeta:egress-meta:' . $retired, $metadata);

        $rememberNode = new ReflectionMethod(NodeEgressProbe::class, 'rememberNode');
        $rememberNode->setAccessible(true);
        $rememberNode->invoke(app(NodeEgressProbe::class), 'current-worker');

        self::assertSame($metadata, Cache::get('javmeta:egress-meta:' . $retired));
        $this->travel(31)->days();
        self::assertNull(Cache::get('javmeta:egress-meta:' . $retired));
    }

    public function test_legacy_node_metadata_expiry_processes_a_bounded_batch_and_keeps_the_rest_pending(): void
    {
        $nodes = array_map(static fn(int $index): string => 'legacy-worker-' . $index, range(1, 201));
        Cache::forever('javmeta:egress:nodes', $nodes);
        foreach ($nodes as $node) {
            Cache::forever('javmeta:egress-meta:' . $node, ['change_count' => 1]);
        }

        $rememberNode = new ReflectionMethod(NodeEgressProbe::class, 'rememberNode');
        $rememberNode->setAccessible(true);
        $rememberNode->invoke(app(NodeEgressProbe::class), 'current-worker');

        self::assertSame(['legacy-worker-201'], Cache::get('javmeta:egress:legacy-meta-pending'));
        $rememberNode->invoke(app(NodeEgressProbe::class), 'current-worker');
        self::assertNull(Cache::get('javmeta:egress:legacy-meta-pending'));

        $this->travel(31)->days();
        foreach ($nodes as $node) {
            self::assertNull(Cache::get('javmeta:egress-meta:' . $node));
        }
    }
}

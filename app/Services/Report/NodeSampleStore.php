<?php

declare(strict_types=1);

namespace App\Services\Report;

use Illuminate\Support\Facades\Cache;

final class NodeSampleStore
{
    private const REGISTRY_KEY = 'javmeta:node-samples:nodes';

    /** @param array<string, mixed> $sample */
    public function put(string $node, array $sample): void
    {
        $ttlMinutes = max(180, (int) config('javmeta_report.sample_retention_minutes', 180));
        $samples = $this->samples($node);
        $samples[] = $sample;
        $samples = array_slice($samples, -$ttlMinutes);
        Cache::put(self::key($node), $samples, now()->addMinutes($ttlMinutes));

        Cache::lock('javmeta:node-samples:registry-lock', 5)->block(1, function () use ($node, $ttlMinutes): void {
            $nodes = Cache::get(self::REGISTRY_KEY, []);
            $nodes = is_array($nodes) ? $nodes : [];
            $nodes[] = $node;
            Cache::put(self::REGISTRY_KEY, array_values(array_unique($nodes)), now()->addMinutes($ttlMinutes));
        });
    }

    /** @return list<array<string, mixed>> */
    public function samples(string $node): array
    {
        $samples = Cache::get(self::key($node), []);

        return is_array($samples) ? array_values(array_filter($samples, 'is_array')) : [];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function all(): array
    {
        $nodes = Cache::get(self::REGISTRY_KEY, []);
        if (! is_array($nodes)) {
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if (is_string($node) && $node !== '') {
                $result[$node] = $this->samples($node);
            }
        }

        return $result;
    }

    public static function key(string $node): string
    {
        return 'javmeta:node-samples:' . rawurlencode($node);
    }
}

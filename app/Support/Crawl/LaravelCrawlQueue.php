<?php

declare(strict_types=1);

namespace App\Support\Crawl;

use App\Models\CrawlQueue;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/** Maps crawl_queue.kind → Laravel queue name (onQueue). */
final class LaravelCrawlQueue
{
    public static function depthLimit(string $queue): int
    {
        $browser = in_array($queue, (array) config('javmeta_queues.browser_names', []), true);

        return max(1, (int) config($browser ? 'javmeta_queues.browser_depth_limit' : 'javmeta_queues.depth_limit', 100));
    }

    public static function readyDepth(string $queue): int
    {
        $connectionName = (string) config('queue.default', 'database');
        $queueConfig = (array) config("queue.connections.{$connectionName}", []);
        $driver = (string) ($queueConfig['driver'] ?? $connectionName);

        if ($driver === 'redis') {
            $connection = (string) ($queueConfig['connection'] ?? 'default');

            return (int) Redis::connection($connection)->llen('queues:' . $queue);
        }

        if ($driver !== 'database') {
            return 0;
        }

        return DB::connection($queueConfig['connection'] ?? null)
            ->table($queueConfig['table'] ?? 'jobs')
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', now()->getTimestamp())
            ->count();
    }

    public static function nameForKind(string $kind): string
    {
        /** @var array<string, string> $names */
        $names = config('javmeta_queues.names', []);
        $name = $names[$kind] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException("No Laravel queue configured for crawl kind [{$kind}].");
        }

        return $name;
    }

    /** @return list<string> */
    public static function allNames(): array
    {
        /** @var array<string, string> $names */
        $names = config('javmeta_queues.names', []);

        return array_values(array_unique([...array_values($names), ...array_values((array) config('javmeta_queues.browser_names', []))]));
    }

    public static function forListing(): string
    {
        return self::nameForKind(CrawlQueue::KIND_LISTING);
    }

    public static function forDetail(): string
    {
        return self::nameForKind(CrawlQueue::KIND_DETAIL);
    }

    public static function forPerformerListing(): string
    {
        return self::nameForKind(CrawlQueue::KIND_PERFORMER_LISTING);
    }

    public static function forPerformerDetail(): string
    {
        return self::nameForKind(CrawlQueue::KIND_PERFORMER_DETAIL);
    }

    public static function forGallery(): string
    {
        return self::nameForKind(CrawlQueue::KIND_GALLERY);
    }
}

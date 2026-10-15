<?php

declare(strict_types=1);

namespace App\Support\Crawl;

use App\Models\CrawlQueue;
use InvalidArgumentException;

/** Maps crawl_queue.kind → Laravel queue name (onQueue). */
final class LaravelCrawlQueue
{
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

        return array_values($names);
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

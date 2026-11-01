<?php

declare(strict_types=1);

use App\Models\CrawlQueue;

$crawlQueueNames = [
    CrawlQueue::KIND_LISTING => 'listing',
    CrawlQueue::KIND_DETAIL => 'detail',
    CrawlQueue::KIND_PERFORMER_LISTING => 'performer_listing',
    CrawlQueue::KIND_PERFORMER_DETAIL => 'performer_detail',
    CrawlQueue::KIND_GALLERY => 'gallery',
];
$browserQueueNames = array_map(static fn(string $name): string => 'browser_' . $name, $crawlQueueNames);
$searchQueueName = 'search';
$workerQueueNames = [...array_values($crawlQueueNames), $searchQueueName];
$observabilityQueueName = 'observability';

/** Laravel queue names used by the feed pool and Horizon supervisors. */
return [
    'names' => $crawlQueueNames,
    'browser_names' => $browserQueueNames,
    'lanes_enabled' => true,
    'lane_browser_percent' => 25,
    'lane_min_attempts' => 40,
    'lane_direct_pins' => [],
    'lane_browser_pins' => [],
    'browser_depth_limit' => 100,
    'browser_workers_per_queue' => max(1, (int) env('JAVMETA_BROWSER_WORKERS_PER_QUEUE', 2)),

    /** Queue names included in the feed-pool queue set; only crawl kinds map to crawl_queue rows. */
    'feed_pool_names' => [...$workerQueueNames, ...array_values($browserQueueNames)],

    'search_queue' => $searchQueueName,
    'observability_queue' => $observabilityQueueName,
    /** Percentage of API spans queued for OpenObserve; deterministic sampling uses the trace ID. */
    'observability_sample_percent' => min(100, max(0, (int) env('JAVMETA_OBSERVABILITY_SAMPLE_PERCENT', 10))),

    /** Maximum ready jobs buffered for the OpenObserve queue. */
    'observability_depth_limit' => max(1, (int) env('JAVMETA_OBSERVABILITY_QUEUE_DEPTH_LIMIT', 1000)),

    /** Queues supervised by Horizon; search and observability are not crawl_queue kinds. */
    'horizon_names' => [...$workerQueueNames, ...array_values($browserQueueNames), $observabilityQueueName],

    /** Workers per queue name on each instance (POC: 1). */
    'workers_per_queue' => max(1, (int) env('JAVMETA_WORKERS_PER_QUEUE', 1)),

    /** Maximum ready Laravel jobs buffered for each crawl queue. */
    'depth_limit' => max(1, (int) env('JAVMETA_QUEUE_DEPTH_LIMIT', 100)),

    /**
     * Fairness: at most this many rows per source and kind may be claimed and unfinished at the same time, so one
     * source with thousands of older rows cannot fill the worker queue and starve the others.
     */
    'per_source_inflight' => max(1, (int) env('JAVMETA_PER_SOURCE_INFLIGHT', 4)),

    /** Jobs the feed pool may dispatch per run (every minute); the per-source in-flight cap and throttle keep each site polite. */
    // Sources that need the browser services, and how many of their rows may be claimed at the same time (0 = no cap).
    // Both are settings in the database (docs/03-operations/settings.md).
    'browser_sources' => ['javlibrary', 'javbus', 'missav', 'jable', 'warashi', 'javdatabase'],
    'browser_inflight' => 8,
    'feed_pool_limit' => max(1, (int) env('JAVMETA_FEED_POOL_LIMIT', 50)),

    /** Seconds of work a source may have queued at once; with its gap this gives its per-minute budget (floor(horizon / gap)). */
    'pacing_horizon_seconds' => max(1, (int) env('JAVMETA_PACING_HORIZON_SECONDS', 60)),

    /** Highest gap (seconds) the throttle may reach after rate limits, blocks and challenges. */
    'gap_blocked_max_seconds' => max(1, (int) env('JAVMETA_GAP_BLOCKED_MAX_SECONDS', 600)),

    'worker_timeout' => (int) env('JAVMETA_QUEUE_TIMEOUT', 180),
];

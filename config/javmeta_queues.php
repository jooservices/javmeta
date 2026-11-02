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
    'browser_workers_per_queue' => 2,
    'direct_workers_per_queue' => null,
    'worker_queue_overrides' => [],
    'worker_node_overrides' => [],

    /** Queue names included in the feed-pool queue set; only crawl kinds map to crawl_queue rows. */
    'feed_pool_names' => [...$workerQueueNames, ...array_values($browserQueueNames)],

    'search_queue' => $searchQueueName,
    'observability_queue' => $observabilityQueueName,
    /** Percentage of API spans queued for OpenObserve; deterministic sampling uses the trace ID. */
    'observability_sample_percent' => 10,

    /** Maximum ready jobs buffered for the OpenObserve queue. */
    'observability_depth_limit' => 1000,

    /** Queues supervised by Horizon; search and observability are not crawl_queue kinds. */
    'horizon_names' => [...$workerQueueNames, ...array_values($browserQueueNames), $observabilityQueueName],

    /** Workers per queue name on each instance (POC: 1). */
    'workers_per_queue' => 1,

    /** Maximum ready Laravel jobs buffered for each crawl queue. */
    'depth_limit' => 100,

    /**
     * Fairness: at most this many rows per source and kind may be claimed and unfinished at the same time, so one
     * source with thousands of older rows cannot fill the worker queue and starve the others.
     */
    'per_source_inflight' => 4,

    /** Per-minute budget is scaled over scheduled five-second checks; the source throttle keeps each site polite. */
    // Sources that need the browser services, and how many of their rows may be claimed at the same time (0 = no cap).
    // Both are settings in the database (docs/03-operations/settings.md).
    'browser_sources' => ['javlibrary', 'javbus', 'missav', 'jable', 'warashi', 'javdatabase'],
    'browser_inflight' => 8,
    'feed_pool_limit' => 50,
    'feed_interval_seconds' => 10,

    /** Seconds of work a source may have queued at once; with its gap this gives its per-minute budget (floor(horizon / gap)). */
    'pacing_horizon_seconds' => 60,

    /** Highest gap (seconds) the throttle may reach after rate limits, blocks and challenges. */
    'gap_blocked_max_seconds' => 600,

    'worker_timeout' => 180,
    'fetch_service_timeout_seconds' => 45,
    'queue_tries' => 3,
    'queue_max_time' => 3600,
    'queue_max_jobs' => 500,
    'queue_backoff_seconds' => 0,
    'queue_sleep_seconds' => 3,
    'stop_grace_seconds' => 200,
];

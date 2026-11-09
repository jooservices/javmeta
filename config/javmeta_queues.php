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
$retryQueueNames = array_map(static fn(string $name): string => 'retry_' . $name, $crawlQueueNames);
$searchQueueName = 'search';
$downloadSearchQueueName = 'download_search';
$workerQueueNames = [...array_values($crawlQueueNames), $searchQueueName];
$observabilityQueueName = 'observability';

/** Laravel queue names used by the feed pool and Horizon supervisors. */
return [
    'names' => $crawlQueueNames,
    'browser_names' => $browserQueueNames,
    'retry_names' => $retryQueueNames,
    'lanes_enabled' => true,
    'lane_browser_percent' => 25,
    'lane_min_attempts' => 40,
    'lane_direct_pins' => [],
    'lane_browser_pins' => [],
    'browser_depth_limit' => 100,
    'browser_workers_per_queue' => 2,
    'retry' => [
        'workers_per_queue' => 1,
        'depth_limit' => 10,
        'feed_pool_limit' => 5,
        'pacing_horizon_seconds' => 60,
        'pacing_gap_multiplier' => 2.0,
        'per_source_inflight' => 1,
        'max_rounds' => 3,
        'delays_seconds' => [1800, 7200, 21600],
        'attempts_per_round' => 3,
        'import_slice_size' => 100,
        'fetch_profile' => [],
    ],
    'direct_workers_per_queue' => null,
    'worker_queue_overrides' => [],
    'worker_node_overrides' => [],
    'postgres_connection_budget' => [
        'role_limit' => (int) env('JAVMETA_POSTGRES_ROLE_CONNECTION_LIMIT', 120),
        'reserve' => (int) env('JAVMETA_POSTGRES_CONNECTION_RESERVE', 30),
        'worker_nodes' => (int) env('JAVMETA_POSTGRES_WORKER_NODE_COUNT', 2),
        'primary_nodes' => (int) env('JAVMETA_POSTGRES_PRIMARY_NODE_COUNT', 1),
    ],
    'source_gap_overrides' => [],

    /** Queue names included in the feed-pool queue set; only crawl kinds map to crawl_queue rows. */
    'feed_pool_names' => [...$workerQueueNames, ...array_values($browserQueueNames), ...array_values($retryQueueNames)],

    'search_queue' => $searchQueueName,
    'download_search_queue' => $downloadSearchQueueName,
    'observability_queue' => $observabilityQueueName,
    /** Percentage of API spans queued for OpenObserve; deterministic sampling uses the trace ID. */
    'observability_sample_percent' => 10,

    /** Maximum ready jobs buffered for the OpenObserve queue. */
    'observability_depth_limit' => 1000,

    /** Queues supervised by Horizon; search and observability are not crawl_queue kinds. */
    'horizon_names' => [...$workerQueueNames, ...array_values($browserQueueNames), ...array_values($retryQueueNames), $downloadSearchQueueName, $observabilityQueueName],

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

    'feed_diagnostics' => [
        'enabled' => true,
        'retention_minutes' => 120,
    ],

    /** Highest gap (seconds) the throttle may reach after rate limits, blocks and challenges. */
    'gap_blocked_max_seconds' => 600,

    'worker_timeout' => 180,
    'fetch_service_timeout_seconds' => 45,
    'playwright_timeout_seconds' => 25,
    'fetch_method_window_size' => 10,
    'fetch_method_failure_threshold' => 8,
    'fetch_method_window_ttl_seconds' => 86400,
    'fetch_method_preference_ttl_seconds' => 21600,
    'fetch_method_probe_interval' => 10,
    'queue_tries' => 3,
    'queue_max_time' => 3600,
    'queue_max_jobs' => 500,
    'queue_backoff_seconds' => 0,
    'queue_sleep_seconds' => 3,
    'stop_grace_seconds' => 200,
];

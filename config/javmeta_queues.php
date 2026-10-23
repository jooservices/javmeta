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
$searchQueueName = 'search';
$workerQueueNames = [...array_values($crawlQueueNames), $searchQueueName];

/**
 * Laravel queue names for crawl jobs (1:1 with crawl_queue.kind).
 * Each worker instance runs one process per name.
 */
return [
    'names' => $crawlQueueNames,

    /** Queue names included in the feed-pool queue set; only crawl kinds map to crawl_queue rows. */
    'feed_pool_names' => $workerQueueNames,

    'search_queue' => $searchQueueName,

    /** Queues supervised by Horizon; search is not backed by a crawl_queue kind. */
    'horizon_names' => $workerQueueNames,

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
    'feed_pool_limit' => max(1, (int) env('JAVMETA_FEED_POOL_LIMIT', 50)),

    /** Seconds of work a source may have queued at once; with its gap this gives its per-minute budget (floor(horizon / gap)). */
    'pacing_horizon_seconds' => max(1, (int) env('JAVMETA_PACING_HORIZON_SECONDS', 60)),

    /** Highest gap (seconds) the throttle may reach after rate limits, blocks and challenges. */
    'gap_blocked_max_seconds' => max(1, (int) env('JAVMETA_GAP_BLOCKED_MAX_SECONDS', 600)),

    'worker_timeout' => (int) env('JAVMETA_QUEUE_TIMEOUT', 180),
];

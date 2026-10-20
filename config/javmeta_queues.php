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

/**
 * Laravel queue names for crawl jobs (1:1 with crawl_queue.kind).
 * Each worker instance runs one process per name.
 */
return [
    'names' => $crawlQueueNames,

    /** Queues supervised by Horizon, including search jobs outside the feed pool. */
    'horizon_names' => [...array_values($crawlQueueNames), 'search'],

    /** Workers per queue name on each instance (POC: 1). */
    'workers_per_queue' => max(1, (int) env('JAVMETA_WORKERS_PER_QUEUE', 1)),

    /** Maximum ready Laravel jobs buffered for each crawl queue. */
    'depth_limit' => max(1, (int) env('JAVMETA_QUEUE_DEPTH_LIMIT', 100)),

    'worker_timeout' => (int) env('JAVMETA_QUEUE_TIMEOUT', 180),
];

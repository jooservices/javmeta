<?php

declare(strict_types=1);

namespace App\Observability;

/** Shared by metric producers and destructive maintenance protection. */
final class MetricStreams
{
    public const PREFIX = 'javmeta_';

    public const NAMES = [
        'source_gap_seconds' => self::PREFIX . 'source_gap_seconds',
        'source_last_success_age_seconds' => self::PREFIX . 'source_last_success_age_seconds',
        'source_past_due' => self::PREFIX . 'source_past_due',
        'crawl_queue_depth' => self::PREFIX . 'crawl_queue_depth',
        'crawl_queue_claimed_age_seconds' => self::PREFIX . 'crawl_queue_claimed_age_seconds',
        'crawl_queue_avg_attempts' => self::PREFIX . 'crawl_queue_avg_attempts',
        'crawl_events_5m' => self::PREFIX . 'crawl_events_5m',
        'api_requests_1m' => self::PREFIX . 'api_requests_1m',
        'api_error_rate_1m' => self::PREFIX . 'api_error_rate_1m',
        'api_p50_ms_1m' => self::PREFIX . 'api_p50_ms_1m',
        'api_p95_ms_1m' => self::PREFIX . 'api_p95_ms_1m',
        'api_429_1m' => self::PREFIX . 'api_429_1m',
        'api_requests_by_endpoint_1m' => self::PREFIX . 'api_requests_by_endpoint_1m',
        'api_p95_ms_by_endpoint_1m' => self::PREFIX . 'api_p95_ms_by_endpoint_1m',
        'movies_needs_review' => self::PREFIX . 'movies_needs_review',
        'review_flags_open' => self::PREFIX . 'review_flags_open',
        'movies_completeness_tier' => self::PREFIX . 'movies_completeness_tier',
        'observation_conflict_rate' => self::PREFIX . 'observation_conflict_rate',
        'crawl_run_pages_fetched_1h' => self::PREFIX . 'crawl_run_pages_fetched_1h',
        'crawl_run_movies_new_1h' => self::PREFIX . 'crawl_run_movies_new_1h',
        'crawl_run_movies_updated_1h' => self::PREFIX . 'crawl_run_movies_updated_1h',
        'crawl_run_failures_1h' => self::PREFIX . 'crawl_run_failures_1h',
        'crawl_run_proxy_requests_1h' => self::PREFIX . 'crawl_run_proxy_requests_1h',
        'crawl_run_proxy_bytes_1h' => self::PREFIX . 'crawl_run_proxy_bytes_1h',
        'crawl_run_browser_fetches_1h' => self::PREFIX . 'crawl_run_browser_fetches_1h',
        'movies_total' => self::PREFIX . 'movies_total',
        'performers_total' => self::PREFIX . 'performers_total',
        'observations_1d' => self::PREFIX . 'observations_1d',
        'failed_jobs' => self::PREFIX . 'failed_jobs',
        'failed_jobs_1h' => self::PREFIX . 'failed_jobs_1h',
        'postgres_connections_used' => self::PREFIX . 'postgres_connections_used',
        'postgres_connections_max' => self::PREFIX . 'postgres_connections_max',
        'postgres_connection_utilization' => self::PREFIX . 'postgres_connection_utilization',
        'mongo_archive_write_failures' => self::PREFIX . 'mongo_archive_write_failures',
        'elasticsearch_request_failures' => self::PREFIX . 'elasticsearch_request_failures',
        'dep_up' => self::PREFIX . 'dep_up',
        'backup_last_success_age_seconds' => self::PREFIX . 'backup_last_success_age_seconds',
        'search_embedding_backfill_missing' => self::PREFIX . 'search_embedding_backfill_missing',
        'search_embedding_backfill_fixed' => self::PREFIX . 'search_embedding_backfill_fixed',
    ];
}

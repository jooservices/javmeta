<?php

declare(strict_types=1);

return [
    'groups' => [
        'queue' => 'Queues',
        'worker' => 'Workers',
        'browser' => 'Browser',
        'retry' => 'Retry lane',
        'cleanup' => 'Cleanup',
        'timeouts' => 'Timeouts',
        'crawl' => 'Crawl loop',
        'alerts' => 'Alerts',
        'notifications' => 'Notification policy',
        'telegram' => 'Telegram',
        'report' => 'Reports',
        'readiness' => 'Readiness checks',
        'search' => 'Search',
        'api_cache' => 'API response cache',
        'observability' => 'Observability',
        'stats' => 'Crawl statistics',
        'backup' => 'Backups',
        'ai' => 'AI (Ollama, embeddings)',
        'follow_notifications' => 'Follow notifications (Telegram)',
        'domains' => 'Domains',
        'library' => 'On-disk library',
        'dataset' => 'Sample data',
        'downloads' => 'Downloads (qBittorrent)',
        'files' => 'File server',
        'media' => 'Media processing',
        'capture' => 'Stream capture',
        'ui_security' => 'UI security',
        'ui' => 'User interface',
        'nodes' => 'Add node',
        'startup_health' => 'Startup health',
        'normalization' => 'Performer normalization',
    ],
    'definitions' => [
        'health__startup__enabled' => [
            'label' => 'Enable startup gate',
            'description' => 'Blocks background commands and web requests while PostgreSQL, Redis or MongoDB is not ready.',
        ],
        'health__startup__timeout_seconds' => [
            'label' => 'Timeout per service (seconds)',
            'description' => 'Limit for each connection check; 2 seconds at most.',
        ],
        'health__startup__wait_seconds' => [
            'label' => 'Wait for primary services (seconds)',
            'description' => 'Default time health:startup keeps retrying the primary services.',
        ],
        'health__startup__retry_backoff_seconds' => [
            'label' => 'Retry interval (seconds)',
            'description' => 'Pause between re-checks of the primary services.',
        ],
        'health__startup__snapshot_ttl_seconds' => [
            'label' => 'Web health snapshot lifetime (seconds)',
            'description' => 'How long the primary check result is reused to reduce request latency.',
        ],
        'health__startup__primary__web' => [
            'label' => 'Primary services for the web node',
            'description' => 'One service per line. PostgreSQL, Redis and MongoDB are always checked; Elasticsearch is a primary service on the web node by default.',
        ],
        'health__startup__primary__worker' => [
            'label' => 'Primary services for workers',
            'description' => 'One service per line. Elasticsearch is secondary on crawl workers by default.',
        ],
        'health__startup__primary__scheduler' => [
            'label' => 'Primary services for the scheduler',
            'description' => 'One service per line. PostgreSQL, Redis and MongoDB are always checked.',
        ],
        'health__startup__command_allowlist' => [
            'label' => 'Commands exempt from the startup gate',
            'description' => 'One Artisan command name or pattern per line, for example health:*, settings:*, migrate*.',
        ],
        'health__startup__command_denylist' => [
            'label' => 'Commands always checked at startup',
            'description' => 'One Artisan command name or pattern per line; this list takes priority over the exempt list.',
        ],
        'health__startup__flaresolverr_url' => [
            'label' => 'FlareSolverr URL',
            'description' => 'Address of the secondary browser service; leave empty if unused.',
        ],
        'health__startup__playwright_url' => [
            'label' => 'Browser gateway URL',
            'description' => 'Address of the secondary Playwright service; leave empty if unused.',
        ],
        'ui__locale' => [
            'label' => 'Interface language',
            'description' => 'Vietnamese or English for the owner interface; guest browsers still choose their own language locally.',
        ],
        'ui_guest__rate_limit_per_minute' => [
            'label' => 'Guest UI requests per minute per IP',
            'description' => 'Maximum requests per minute from one IP address to the public read-only UI bridge.',
        ],
        'ui_security__login_required' => [
            'label' => 'Require owner login',
            'description' => 'Protects the UI and admin settings with an owner login session.',
        ],
        'ui_security__owner_admin_token' => [
            'label' => 'Owner admin token',
            'description' => 'Encrypted in the database and never shown again.',
        ],
        'ui_security__mcp_api_key' => [
            'label' => 'Legacy MCP API key',
            'description' => 'Encrypted in the database and never shown again; new API keys should use the MCP scope.',
        ],
        'nodes__enrollment_ttl_minutes' => [
            'label' => 'Node enrollment code lifetime (minutes)',
            'description' => 'The one-time code for adding a node expires after this many minutes.',
        ],
        'nodes__release_repo_url' => [
            'label' => 'Source repository for new nodes',
            'description' => 'Git address new nodes install from; nodes must be able to read this repository.',
        ],
        'nodes__release_ref' => [
            'label' => 'Branch, tag or commit for new nodes',
            'description' => 'Release that new nodes install; should match the running version.',
        ],
        'dataset__allowed_databases' => [
            'label' => 'Databases allowed to load sample data',
            'description' => 'Sample data can be loaded and deleted only on these databases; outside testing, --force is always required.',
        ],
        'perf__warm_enabled' => [
            'label' => 'Cache warming',
            'description' => 'Refreshes the status and whole-catalog filters on a schedule, reducing wait time when the cache expires.',
        ],
        'api__response_cache__enabled' => [
            'label' => 'Enable API response cache',
            'description' => 'Turn off to bypass response caching; the API continues to serve data directly when Redis is unavailable.',
        ],
        'api__response_cache__ttl__lists' => ['label' => 'List TTL (seconds)', 'description' => 'How long movie and performer lists are cached.'],
        'api__response_cache__ttl__facets' => ['label' => 'Facet TTL (seconds)', 'description' => 'How long movie and performer facets are cached.'],
        'api__response_cache__ttl__catalog' => ['label' => 'Catalog TTL (seconds)', 'description' => 'How long infrequently changing catalog responses are cached.'],
        'api__response_cache__ttl__suggestions' => ['label' => 'Search suggestion TTL (seconds)', 'description' => 'How long search suggestion responses are cached.'],
        'api__response_cache__ttl__status' => ['label' => 'Status TTL (seconds)', 'description' => 'How long the system status summary is cached.'],
        'api__response_cache__ttl__movie_detail' => ['label' => 'Movie detail TTL (seconds)', 'description' => 'How long movie detail responses are cached.'],
        'search__budget_ms' => [
            'label' => 'Search budget (milliseconds)',
            'description' => 'Total time for optional HTTP calls in one hybrid search. When it runs out, keyword results are returned; database queries are not limited.',
        ],
        'capture__enabled' => [
            'label' => 'Enable stream capture',
            'description' => 'OFF by default. Starts only from an explicit owner action; DRM is always refused.',
        ],
        'capture__max_duration_sec' => [
            'label' => 'Maximum capture duration (seconds)',
            'description' => 'Limit for a single capture.',
        ],
        'capture__max_bytes' => [
            'label' => 'Maximum capture size (bytes)',
            'description' => 'Stops before the disk fills up.',
        ],
        'capture__max_playlists' => [
            'label' => 'Maximum nested playlists',
            'description' => 'Rejects loops and oversized playlists.',
        ],
        'capture__max_manifest_bytes' => [
            'label' => 'Maximum manifest size (bytes)',
            'description' => 'Limit for HTML pages and playlists downloaded for inspection.',
        ],
        'capture__http_timeout_sec' => [
            'label' => 'Capture HTTP timeout (seconds)',
            'description' => 'No cookies or accounts are used to bypass protection.',
        ],
        'media__vision_model' => [
            'label' => 'Scene description model',
            'description' => 'Internal Ollama vision model; images are never sent to the cloud.',
        ],
        'media__scenes_index' => [
            'label' => 'Elasticsearch scene search index',
            'description' => 'Created when the first vector arrives. Renaming requires reindexing.',
        ],
        'media__embedding_dimensions' => [
            'label' => 'Scene vector dimensions',
            'description' => 'Must match the embedder service and the Elasticsearch mapping.',
        ],
        'media__translate_model' => [
            'label' => 'Subtitle translation model',
            'description' => 'Runs only a local Ollama model to translate to EN and VI.',
        ],
        'media__translate_batch_segments' => [
            'label' => 'Subtitle segments per translation batch',
            'description' => 'Keeps timestamps and sequence numbers unchanged.',
        ],
        'media__ollama_timeout_sec' => [
            'label' => 'Media Ollama timeout (seconds)',
            'description' => 'Stops on error; no cloud fallback.',
        ],
        'media__whisper_model' => [
            'label' => 'Speech recognition model',
            'description' => 'Whisper runs CUDA float16 on the internal GPU; it never falls back to the cloud.',
        ],
        'media__api_url' => [
            'label' => 'JavMeta URL for workers',
            'description' => 'Internal address of the JavMeta API.',
        ],
        'media__output_folder' => [
            'label' => 'Media output folder',
            'description' => 'Images, subtitles and captured MP4 files are written here; nothing is written to the library.',
        ],
        'media__max_parallel' => [
            'label' => 'Concurrent media jobs',
            'description' => 'Shared limit across all workers.',
        ],
        'media__max_attempts' => [
            'label' => 'Maximum media attempts',
            'description' => 'After this the owner must click retry.',
        ],
        'media__lease_seconds' => [
            'label' => 'Job lease duration (seconds)',
            'description' => 'Must be longer than the maximum time of a single job.',
        ],
        'media__job_timeout_sec' => [
            'label' => 'Maximum time of a media job',
            'description' => 'Stops processes that run too long (seconds).',
        ],
        'media__poll_sec' => [
            'label' => 'Idle wait when the queue is empty (seconds)',
            'description' => 'Workers re-read the configuration on every cycle.',
        ],
        'media__thumb_interval_sec' => [
            'label' => 'Preview image interval (seconds)',
            'description' => 'Takes one image every this many seconds.',
        ],
        'media__scene_threshold' => [
            'label' => 'Scene change threshold',
            'description' => 'Visual difference required to split a scene.',
        ],
        'media__ffmpeg_threads' => [
            'label' => 'ffmpeg threads',
            'description' => 'Limits CPU usage to keep the machine responsive.',
        ],
        'queue__workers_per_queue' => [
            'label' => 'Default workers per queue',
            'description' => 'Default number of Horizon processes for each queue; per-lane, per-queue-type and per-node settings can override it.',
        ],
        'worker__direct_workers_per_queue' => [
            'label' => 'Workers per direct-lane queue',
            'description' => 'Leave empty to use the default worker count; a separate setting for queues that do not use a browser.',
        ],
        'worker__browser_workers_per_queue' => [
            'label' => 'Workers per browser-lane queue',
            'description' => 'Maximum Horizon processes for each queue that needs Playwright or FlareSolverr.',
        ],
        'worker__retry_workers_per_queue' => [
            'label' => 'Workers per retry-lane queue',
            'description' => 'Number of Horizon processes for each retry queue; a single process by default.',
        ],
        'worker__queue_overrides' => [
            'label' => 'Per-queue worker overrides (JSON)',
            'description' => 'JSON object mapping a queue name to a worker count from 1 to 32, for example {"listing":2,"browser_detail":1}.',
        ],
        'worker__node_overrides' => [
            'label' => 'Per-node worker overrides (JSON)',
            'description' => 'Maps main, worker or role@hostname to default, direct, browser, retry and queues.',
        ],
        'worker__queue_names' => [
            'label' => 'Queue names to run',
            'description' => 'Each Horizon worker watches one name from the list; a crawl queue automatically adds its matching browser queue.',
        ],
        'worker__queue_tries' => [
            'label' => 'Queue retry attempts',
            'description' => 'Number of times Horizon tries a job before marking it failed.',
        ],
        'worker__queue_max_time' => [
            'label' => 'Maximum worker process lifetime (seconds)',
            'description' => 'Horizon replaces the process after this time to free memory.',
        ],
        'worker__queue_max_jobs' => [
            'label' => 'Maximum jobs per process',
            'description' => 'Horizon replaces the process after this many jobs; 0 is not allowed to avoid processes that run forever.',
        ],
        'worker__queue_backoff_seconds' => [
            'label' => 'Delay before retrying a job (seconds)',
            'description' => 'Base delay Horizon waits before retrying a failed job.',
        ],
        'worker__queue_sleep_seconds' => [
            'label' => 'Pause between job pickups (seconds)',
            'description' => 'Workers use this value when the queue has no work.',
        ],
        'worker__stop_grace_seconds' => [
            'label' => 'Graceful worker stop timeout (seconds)',
            'description' => 'Horizon lets running jobs finish within this limit; the value must not be lower than the worker timeout.',
        ],
        'worker__reconcile_cooldown_seconds' => [
            'label' => 'Minimum interval between worker changes (seconds)',
            'description' => 'Keeps each node at least this long between two changes of the worker count.',
        ],
        'worker__reconcile_jitter_seconds' => [
            'label' => 'Stable delay between nodes (seconds)',
            'description' => 'Each node waits a different amount of time before changing its worker count; the fleet lock ensures only one node changes at a time.',
        ],
        'queue__depth_limit' => [
            'label' => 'Queue full threshold',
            'description' => 'Above this many waiting jobs, the readiness check reports a full queue.',
        ],
        'queue__per_source_inflight' => [
            'label' => 'Concurrent jobs per source',
            'description' => 'Maximum number of jobs from one source that may run at the same time.',
        ],
        'queue__feed_pool_limit' => [
            'label' => 'Jobs fed per cycle',
            'description' => 'Maximum number of crawl_queue rows fed in one feed pool cycle.',
        ],
        'queue__feed_interval_seconds' => [
            'label' => 'Queue feed interval (seconds)',
            'description' => 'Choose 5, 10, 15, 30 or 60 seconds; the feeder checks the configuration every 5 seconds, so changes need no scheduler restart.',
        ],
        'queue__pacing_horizon_seconds' => [
            'label' => 'Pacing horizon (seconds)',
            'description' => 'Window used to spread requests for each source.',
        ],
        'queue__feed_diagnostics__enabled' => [
            'label' => 'Enable feed pool diagnostics',
            'description' => 'Records feed pool budget, run counts and stop reasons in Redis.',
        ],
        'queue__feed_diagnostics__retention_minutes' => [
            'label' => 'Feed pool diagnostics retention (minutes)',
            'description' => 'Minutes to keep feed pool counters in Redis for metric export and reports.',
        ],
        'queue__gap_blocked_max_seconds' => [
            'label' => 'Maximum delay when blocked (seconds)',
            'description' => 'When a source is blocked, the delay between requests doubles up to this value.',
        ],
        'queue__retry_after_seconds' => [
            'label' => 'Job hold time before retry (seconds)',
            'description' => 'Must be longer than the worker timeout so a job still being processed does not run twice.',
        ],
        'queue__observability_sample_percent' => [
            'label' => 'Telemetry sampling rate (%)',
            'description' => 'Percentage of requests sent to the OpenObserve queue.',
        ],
        'queue__observability_depth_limit' => [
            'label' => 'Maximum telemetry queue depth',
            'description' => 'Limits the number of events waiting to be sent to OpenObserve.',
        ],
        'browser__max_concurrency' => [
            'label' => 'Concurrent browsers',
            'description' => 'Limits the number of active browser sessions on the service machine.',
        ],
        'browser__flaresolverr_url' => [
            'label' => 'FlareSolverr service URL',
            'description' => 'Address of the CAPTCHA-solving service on the internal network; changes apply to new jobs.',
        ],
        'browser__playwright_url' => [
            'label' => 'Playwright service URL',
            'description' => 'Address of the Playwright gateway on the internal network; changes apply to new jobs.',
        ],
        'browser__max_queue' => [
            'label' => 'Maximum pending browser requests',
            'description' => 'Limits the queue length of the browser service.',
        ],
        'browser__playwright_request_timeout_ms' => [
            'label' => 'Playwright request timeout (milliseconds)',
            'description' => 'Time limit for a single navigation request on the browser service.',
        ],
        'browser__max_requests_per_browser' => [
            'label' => 'Maximum requests per browser',
            'description' => 'Closes and recreates the browser session after this many requests.',
        ],
        'browser__flaresolverr_max_timeout_ms' => [
            'label' => 'FlareSolverr maximum timeout (milliseconds)',
            'description' => 'Time limit for FlareSolverr to wait for a page response.',
        ],
        'browser__gateway_wait_timeout_ms' => [
            'label' => 'Maximum gateway wait for the service (milliseconds)',
            'description' => 'Time limit for the gateway to wait for a result from the browser service.',
        ],
        'browser__gateway_client_timeout_ms' => [
            'label' => 'Gateway client timeout (milliseconds)',
            'description' => 'Must be longer than the gateway wait for the service so the client receives the result.',
        ],
        'browser__config_poll_interval_seconds' => [
            'label' => 'Service configuration polling interval (seconds)',
            'description' => 'The browser gateway and watchdog re-read the configuration through the API at this interval.',
        ],
        'browser__config_api_client_ms' => [
            'label' => 'Configuration client timeout (milliseconds)',
            'description' => 'Maximum time a remote service waits for the XCrawler configuration API.',
        ],
        'timeouts__fetch_service_seconds' => [
            'label' => 'Fetch service timeout (seconds)',
            'description' => 'Limit for a single fetch method; the worker client timeout must be longer than this value.',
        ],
        'timeouts__playwright_seconds' => [
            'label' => 'Playwright maximum timeout (seconds)',
            'description' => 'Stops a Playwright call and caps the browser service timeout through the configuration API to free the slot.',
        ],
        'crawl__fetch_method_window_size' => [
            'label' => 'Attempts in the method selection window',
            'description' => 'Number of most recent Playwright results used to learn the fetch method for each source and host.',
        ],
        'crawl__fetch_method_failure_threshold' => [
            'label' => 'Playwright failures to prefer FlareSolverr',
            'description' => 'This threshold must be reached within the window, with at least one recent FlareSolverr success; the effective threshold never exceeds the window size.',
        ],
        'crawl__fetch_method_window_ttl_seconds' => [
            'label' => 'Method learning result lifetime (seconds)',
            'description' => 'How long the success and failure window is kept in the shared cache.',
        ],
        'crawl__fetch_method_preference_ttl_seconds' => [
            'label' => 'FlareSolverr preference duration (seconds)',
            'description' => 'How long FlareSolverr is preferred after Playwright exceeds the failure threshold; a successful probe removes the preference early.',
        ],
        'crawl__fetch_method_probe_interval' => [
            'label' => 'Requests between Playwright probes',
            'description' => 'Every this many requests, Playwright is tried first to detect when the source works again.',
        ],
        'crawl__source_gap_overrides' => [
            'label' => 'Per-source request gap overrides (JSON)',
            'description' => 'Maps a configured or stored source code to min, default and max from 0.25 to 600 seconds; leave empty to keep the default pacing. The 14 CrawlerX 1.5.0 sources start at 8 seconds (min 4, max 60); adjust pacing through this map.',
        ],
        'queue__browser_inflight' => [
            'label' => 'Concurrent browser jobs (total)',
            'description' => 'Shared limit for jobs that use FlareSolverr or Playwright, to avoid overloading the services.',
        ],
        'queue__browser_depth_limit' => [
            'label' => 'Browser queue depth',
            'description' => 'Maximum waiting jobs in each browser-lane queue.',
        ],
        'retry__depth_limit' => [
            'label' => 'Retry queue depth',
            'description' => 'Maximum waiting jobs in each dedicated retry-lane queue.',
        ],
        'retry__feed_pool_limit' => [
            'label' => 'Retry dispatch budget per minute',
            'description' => 'Separate limit for the number of retry-lane jobs sent to workers each minute.',
        ],
        'retry__pacing_horizon_seconds' => [
            'label' => 'Retry lane pacing window (seconds)',
            'description' => 'Separate window that limits the amount of retry work running or waiting per source.',
        ],
        'retry__pacing_gap_multiplier' => [
            'label' => 'Retry request gap multiplier',
            'description' => 'Multiplies the current source delay so retries are slower than the normal crawl flow.',
        ],
        'retry__per_source_inflight' => [
            'label' => 'Concurrent retries per source',
            'description' => 'Separate limit on retry jobs waiting or running at the same time for each source.',
        ],
        'retry__delays_seconds' => [
            'label' => 'Wait between retry rounds (seconds)',
            'description' => 'List of waits before each round; the default is 30 minutes, 2 hours and 6 hours.',
        ],
        'retry__max_rounds' => [
            'label' => 'Maximum retry rounds',
            'description' => 'After these rounds, remaining failures are kept as permanently failed.',
        ],
        'retry__attempts_per_round' => [
            'label' => 'Attempts per retry round',
            'description' => 'Each retry round uses this many attempts before moving to the next round or failing permanently.',
        ],
        'retry__import_slice_size' => [
            'label' => 'Legacy failure import slice size',
            'description' => 'Maximum number of failed rows checked and updated in one import command slice.',
        ],
        'retry__fetch_profile' => [
            'label' => 'Retry lane fetch profile (JSON)',
            'description' => 'Overrides the browser endpoint options, HTTP proxy, egress label and fetch order for retries only.',
        ],
        'queue__browser_sources' => [
            'label' => 'Sources that need a browser (one source code per line)',
            'description' => 'List of sources that must use the browser service.',
        ],
        'crawl__lanes_enabled' => [
            'label' => 'Split crawl lanes',
            'description' => 'Separates direct HTTP and browser queues; jobs already waiting are still processed when turned off.',
        ],
        'crawl__lane_browser_percent' => [
            'label' => 'Browser usage rate (%)',
            'description' => 'A source that reaches this share of browser fetches is moved to the browser lane automatically during the health check.',
        ],
        'crawl__lane_min_attempts' => [
            'label' => 'Minimum fetches for lane assignment',
            'description' => 'Lanes are assigned automatically only with enough fetches in 24 hours; skipped fetches are ignored.',
        ],
        'crawl__lane_direct_pins' => [
            'label' => 'Pin sources to the direct lane',
            'description' => 'One source code per line that should use direct fetch.',
        ],
        'crawl__lane_browser_pins' => [
            'label' => 'Pin sources to the browser lane',
            'description' => 'One source code per line that should use the browser.',
        ],
        'queue__worker_timeout' => [
            'label' => 'Fetch client timeout (seconds)',
            'description' => 'Maximum time a worker waits for a fetch; must be longer than the fetch service timeout.',
        ],
        'crawl__stale_claim_timeout_seconds' => [
            'label' => 'Stuck jobs released after (seconds)',
            'description' => 'Jobs that have started are reclaimed after this time; jobs that have not started still wait according to the queue limit and worker timeout.',
        ],
        'crawl__block_delays_seconds' => [
            'label' => 'Wait when blocked (seconds, comma-separated)',
            'description' => 'Blocks and challenges in the normal flow wait according to this list before moving to the retry lane.',
        ],
        'crawl__retry_backoff_seconds' => [
            'label' => 'Backoff between crawl attempts (seconds)',
            'description' => 'Delay by attempt number before moving to the dedicated retry round.',
        ],
        'crawl__fresh_interval_minutes' => [
            'label' => 'Re-read the first page every (minutes)',
            'description' => 'Interval for re-reading the first page of a listing to catch new movies.',
        ],
        'crawl__loop_pause_seconds' => [
            'label' => 'Pause between listing loops (seconds)',
            'description' => 'After reading the whole listing, waits and then starts again from page 1.',
        ],
        'crawl__recrawl_after_days' => [
            'label' => 'Recrawl incomplete movies after (days)',
            'description' => 'Movies below the minimum completeness tier are recrawled when the last crawl is older than this.',
        ],
        'crawl__recrawl_min_tier' => [
            'label' => 'Minimum completeness tier',
            'description' => 'Movies below this tier are considered incomplete.',
        ],
        'crawl__recrawl_batch' => [
            'label' => 'Movies recrawled per batch',
            'description' => 'Each hour, recrawls at most this many incomplete movies.',
        ],
        'crawl__recrawl_max_rounds' => [
            'label' => 'Maximum recrawls per movie',
            'description' => 'After this many rounds the movie is no longer recrawled.',
        ],
        'crawl__max_attempts' => [
            'label' => 'Maximum crawl attempts',
            'description' => 'After this many attempts, temporary errors move to the dedicated retry lane; parse errors and terminal errors still fail immediately.',
        ],
        'crawl__circuit_failure_threshold' => [
            'label' => 'Failures to open the source circuit',
            'description' => 'After this many consecutive failures, requests to the source are paused.',
        ],
        'crawl__circuit_cooldown_seconds' => [
            'label' => 'Source circuit cooldown (seconds)',
            'description' => 'Wait before retrying a source whose circuit is open.',
        ],
        'alerts__enabled' => [
            'label' => 'Enable alerts',
            'description' => 'When off, no alerts are sent.',
        ],
        'alerts__evaluate_interval_seconds' => [
            'label' => 'Check alerts every (seconds)',
            'description' => 'The command runs every minute and skips runs that are not yet due for this check.',
        ],
        'alerts__window_minutes' => [
            'label' => 'Crawl evaluation window (minutes)',
            'description' => 'Time span used to calculate success and blocked rates.',
        ],
        'alerts__success_rate_min_percent' => [
            'label' => 'Minimum download success rate (%)',
            'description' => 'Alerts when the HTTP 2xx response rate falls below this threshold.',
        ],
        'alerts__success_rate_critical_percent' => [
            'label' => 'Critical success rate threshold (%)',
            'description' => 'Raises CRITICAL when the success rate falls below this threshold within the evaluation window.',
        ],
        'alerts__blocked_ratio_max_percent' => [
            'label' => 'Maximum blocked rate (%)',
            'description' => 'Alerts when the rate of 403, 429 or Cloudflare responses exceeds this threshold.',
        ],
        'alerts__pg_connections_max' => [
            'label' => 'Maximum PostgreSQL connections',
            'description' => 'Alerts when the number of client connections exceeds this threshold.',
        ],
        'alerts__dispatch_age_max_seconds' => [
            'label' => 'Crawl not dispatched for more than (seconds)',
            'description' => 'Alerts when work is due but nothing has been dispatched within this period.',
        ],
        'alerts__crawl_dispatch_warning_seconds' => [
            'label' => 'Crawl dispatch metric threshold (seconds)',
            'description' => 'Warning threshold of the javmeta_crawl_dispatch_stale metric; direct alerts use their own threshold above.',
        ],
        'alerts__backlog_due_min_per_hot_source' => [
            'label' => 'Minimum due jobs per hot source',
            'description' => 'Alerts when a crawling source has fewer due jobs than this.',
        ],
        'alerts__min_attempts_in_window' => [
            'label' => 'Minimum attempts in the window',
            'description' => 'Rates are not calculated when the sample is smaller than this.',
        ],
        'alerts__cooldown_minutes' => [
            'label' => 'Repeat after (minutes)',
            'description' => 'If the problem continues, resends at most once after this period.',
        ],
        'alerts__recovery_notice' => [
            'label' => 'Send a message on recovery',
            'description' => 'Sends one message when the alert condition returns to normal.',
        ],
        'alerts__recovery_retry_max_attempts' => [
            'label' => 'Maximum recovery message attempts',
            'description' => 'After each repeat period, retries a recovery message that did not reach the channel; stops after this many attempts.',
        ],
        'alerts__channels' => [
            'label' => 'Alert channels',
            'description' => 'Each line selects an enabled channel: telegram or webhook.',
        ],
        'alerts__webhook_url' => [
            'label' => 'Alert webhook URL',
            'description' => 'HTTPS URL without credentials, query or fragment; leave empty if no webhook is used.',
        ],
        'alerts__webhook_timeout_seconds' => [
            'label' => 'Webhook maximum wait (seconds)',
            'description' => 'Time limit for sending an alert to the webhook.',
        ],
        'alerts__history_retention_days' => [
            'label' => 'Alert history retention (days)',
            'description' => 'Handled history older than this is deleted automatically.',
        ],
        'alerts__debounce_seconds' => [
            'label' => 'Merge duplicate alerts within (seconds)',
            'description' => 'The same alert is sent only once within this period.',
        ],
        'alerts__throttle_seconds' => [
            'label' => 'At most one alert per cause within (seconds)',
            'description' => 'Applies to the watchdog, backups, stalled crawl and stuck queues.',
        ],
        'alerts__miss_debounce_seconds' => [
            'label' => 'Merge missing-data alerts (seconds)',
            'description' => 'Merge window for alerts about movies or performers that were requested but are not available.',
        ],
        'alerts__title_failure_threshold' => [
            'label' => 'Consecutive failures before alerting',
            'description' => 'A movie that fails more than this many times triggers an alert.',
        ],
        'alerts__heartbeat_stale_seconds' => [
            'label' => 'Alert when a worker is silent for (seconds)',
            'description' => 'The watchdog treats a worker heartbeat older than this as inactive.',
        ],
        'alerts__source_stale_seconds' => [
            'label' => 'Alert when a source has no success for (seconds)',
            'description' => 'Default threshold; the source cycle in the manifest gets one extra sweep added.',
        ],
        'alerts__crawl_stall_seconds' => [
            'label' => 'Alert when crawl is stalled for (seconds)',
            'description' => 'The watchdog checks how long there has been no new movie.',
        ],
        'alerts__queue_stuck_seconds' => [
            'label' => 'Alert when a queue is stuck for (seconds)',
            'description' => 'The watchdog checks for work that has waited too long without being processed.',
        ],
        'alerts__crawl_dispatch_critical_seconds' => [
            'label' => 'Crawl dispatch metric critical threshold (seconds)',
            'description' => 'Critical threshold of the javmeta_crawl_dispatch_stale metric; direct alerts use their own threshold.',
        ],
        'cleanup__pending_queue_limit' => [
            'label' => 'Pending jobs threshold before alerting',
            'description' => 'The watchdog alerts when the number of waiting jobs exceeds this.',
        ],
        'cleanup__watchdog_stall_after_minutes' => [
            'label' => 'Watchdog 45-minute threshold (minutes)',
            'description' => 'The remote watchdog marks a task as not progressing after this period.',
        ],
        'cleanup__temp_data_max_age_hours' => [
            'label' => 'Maximum temporary data age (hours)',
            'description' => 'The remote watchdog cleans temporary data older than this.',
        ],
        'telegram__owner_user_id' => [
            'label' => 'Telegram owner user ID',
            'description' => 'Required for groups: only this user can send commands and press buttons. Leave empty for a private chat.',
        ],
        'telegram__api_base' => [
            'label' => 'Telegram API URL',
            'description' => 'HTTPS address of the Telegram API; HTTP is allowed only on localhost.',
        ],
        'telegram__timeout' => [
            'label' => 'Telegram timeout (seconds)',
            'description' => 'After this time a message is considered not sent.',
        ],
        'telegram__bot_enabled' => [
            'label' => 'Enable Q&A bot',
            'description' => 'The bot answers owner messages: search movies, performers, status and follows. The bot token and chat ID are configured separately in runtime secrets.',
        ],
        'telegram__bot_max_results' => [
            'label' => 'Bot results per group',
            'description' => 'At most 10.',
        ],
        'follow_notifications__timezone' => [
            'label' => 'Notification and library scan time zone',
            'description' => 'Time zone for follow notifications, the Telegram footer and the library scan schedule.',
        ],
        'report__state_path' => [
            'label' => 'Report state file',
            'description' => 'Path of the file that stores the hourly report send marker.',
        ],
        'report__enabled' => [
            'label' => 'Enable periodic reports',
            'description' => 'Schedule that automatically sends a status report through Telegram.',
        ],
        'report__interval_minutes' => [
            'label' => 'Report interval (minutes)',
            'description' => 'Reports run at fixed marks during the day in the selected time zone.',
        ],
        'report__timezone' => [
            'label' => 'Report time zone',
            'description' => 'IANA time zone name, for example America/Mexico_City.',
        ],
        'report__quiet_hours_start' => [
            'label' => 'Quiet hours start',
            'description' => 'Leave empty to disable quiet hours; format HH:MM.',
        ],
        'report__quiet_hours_end' => [
            'label' => 'Quiet hours end',
            'description' => 'The end time is exclusive; format HH:MM.',
        ],
        'report__sections' => [
            'label' => 'Report sections',
            'description' => 'Only the selected sections appear in the report.',
        ],
        'report__top_sources_limit' => [
            'label' => 'Maximum sources in the list',
            'description' => 'Limits the list of blocked or exhausted sources.',
        ],
        'report__channels' => [
            'label' => 'Report channels',
            'description' => 'Telegram is the only delivery channel currently enabled.',
        ],
        'report__history_days' => [
            'label' => 'Report history retention (days)',
            'description' => 'Reports older than this are deleted automatically after sending.',
        ],
        'report__worker_busy_ttl_seconds' => [
            'label' => 'Worker busy marker lifetime (seconds)',
            'description' => 'The marker expires on its own if the process ends without emitting a completion event.',
        ],
        'report__sample_retention_minutes' => [
            'label' => 'Node sample retention (minutes)',
            'description' => 'CPU, RAM, disk and worker samples are kept in the shared Redis.',
        ],
        'report__sample_max_age_seconds' => [
            'label' => 'Maximum age of a fresh node sample (seconds)',
            'description' => 'A node without a fresh sample shows no data in the report.',
        ],
        'report__node_volume_path' => [
            'label' => 'Node disk usage measurement folder',
            'description' => 'Path located on the node application volume.',
        ],
        'report__browser_stats_url' => [
            'label' => 'Browser statistics URL (optional)',
            'description' => 'Internal endpoint that returns the number of Chrome processes and busy/total Playwright slots.',
        ],
        'report__template' => [
            'label' => 'Report text template',
            'description' => 'English template text. You can use the variables {header}, {movies}, {performers}, {crawl}, {sources}, {fleet}, {workers}, {servers}, {services}, {notification_events}, {deploys}, {backup}, {warnings}.',
        ],
        'notifications__quiet_mode' => [
            'label' => 'Quiet mode',
            'description' => 'Sends only CRITICAL alerts and security events; other levels are kept for the report.',
        ],
        'report__daily_time' => [
            'label' => 'Daily report time',
            'description' => 'Sends one 24-hour data report each day in the daily report time zone.',
        ],
        'report__daily_timezone' => [
            'label' => 'Daily report time zone',
            'description' => 'IANA time zone name used to schedule the daily report.',
        ],
        'report__legacy_hourly_enabled' => [
            'label' => 'Enable legacy hourly report',
            'description' => 'Runs javmeta:hourly-report only when enabled and the new periodic report is off.',
        ],
        'readiness__max_newest_movie_age_minutes' => [
            'label' => 'Newest movie no older than (minutes)',
            'description' => 'Older than this means the crawl is not running.',
        ],
        'readiness__max_dead_sources' => [
            'label' => 'Maximum dead sources',
            'description' => 'Number of enabled sources past their schedule; exceeding this reports an error.',
        ],
        'readiness__max_backup_age_hours' => [
            'label' => 'Backup no older than (hours)',
            'description' => 'Older than this reports an error.',
        ],
        'search__enabled' => [
            'label' => 'Enable Elasticsearch search',
            'description' => 'When off, only database search is used.',
        ],
        'search__verify_ssl' => [
            'label' => 'Verify Elasticsearch TLS certificate',
            'description' => 'Turns on TLS certificate verification for the Elasticsearch server.',
        ],
        'search__embedder_enabled' => [
            'label' => 'Enable vectors (semantic search)',
            'description' => 'When off, documents are indexed without vectors.',
        ],
        'search__embedder_url' => [
            'label' => 'Embedder service URL (E5)',
            'description' => 'Used when the embedding provider is the embedder.',
        ],
        'search__embedding_dimensions' => [
            'label' => 'Vector dimensions',
            'description' => 'Change with php artisan search:switch-embeddings, not by hand: the index must be rebuilt.',
        ],
        'search__movies_index' => [
            'label' => 'Elasticsearch movies index',
            'description' => 'search:switch-embeddings builds a new index and switches to it; the old index is kept so you can roll back.',
        ],
        'search__performers_index' => [
            'label' => 'Elasticsearch performers index',
            'description' => 'Name of the index that stores performer vectors and data.',
        ],
        'ai__embedding_provider' => [
            'label' => 'Embedding provider',
            'description' => 'embedder (local E5) or ollama. Change with php artisan search:switch-embeddings <provider> so the index is rebuilt safely.',
        ],
        'ai__ollama_url' => [
            'label' => 'Ollama URL',
            'description' => 'For example http://127.0.0.1:11434. Check with php artisan ai:check.',
        ],
        'ai__ollama_embed_model' => [
            'label' => 'Ollama embedding model',
            'description' => 'For example nomic-embed-text.',
        ],
        'ai__ollama_chat_model' => [
            'label' => 'Ollama chat model',
            'description' => 'Used to understand natural-language questions.',
        ],
        'ai__ollama_timeout' => [
            'label' => 'Ollama timeout (seconds)',
            'description' => 'Time limit for a single request to Ollama.',
        ],
        'ai__nl_timeout_seconds' => [
            'label' => 'Maximum wait for Ollama to read a question (seconds)',
            'description' => 'Hard limit of two seconds for the question-understanding step; on timeout, basic rules are used.',
        ],
        'ai__ollama_keep_alive' => [
            'label' => 'Keep model in memory (for example 30m)',
            'description' => 'Ollama keeps the loaded model for this period; the ai:warm command reloads it before it expires.',
        ],
        'ai__nl_enabled' => [
            'label' => 'Natural-language understanding',
            'description' => 'Reads filters (maker, genre, year, duration, performer) from a question. Basic rules are always available; with Ollama, the model is used first.',
        ],
        'ai__cloud_enabled' => [
            'label' => 'Allow cloud AI',
            'description' => 'OFF. Per the decision document (JOOXCR-230, pending approval), only search questions typed by the owner may be sent, never movie content, images or audio.',
        ],
        'observability__fetch_attempts_retention_days' => [
            'label' => 'Fetch attempt history retention (days)',
            'description' => 'Old fetch attempts are deleted automatically after this many days.',
        ],
        'observability__openobserve_enabled' => [
            'label' => 'Send logs and metrics to OpenObserve',
            'description' => 'When off, no telemetry is written.',
        ],
        'observability__openobserve_timeout_seconds' => [
            'label' => 'OpenObserve timeout (seconds)',
            'description' => 'Time limit for sending telemetry to OpenObserve.',
        ],
        'observability__openobserve_org' => [
            'label' => 'OpenObserve organization',
            'description' => 'Name of the organization that receives logs and metrics.',
        ],
        'observability__openobserve_log_stream' => [
            'label' => 'OpenObserve log stream',
            'description' => 'Name of the stream that stores application logs.',
        ],
        'observability__openobserve_metrics_stream' => [
            'label' => 'OpenObserve metrics stream',
            'description' => 'Name of the stream that stores application metrics.',
        ],
        'observability__openobserve_service_name' => [
            'label' => 'OpenObserve service name',
            'description' => 'Application name shown in the telemetry data.',
        ],
        'observability__trace_sample_rate' => [
            'label' => 'Trace sampling rate (0 to 1)',
            'description' => '1 samples every request.',
        ],
        'observability__sentry_traces_sample_rate' => [
            'label' => 'Sentry trace sampling rate (0 to 1)',
            'description' => '0 turns Sentry tracing off.',
        ],
        'observability__egress_probe_urls' => [
            'label' => 'Egress IP check URLs',
            'description' => 'Each line is a service that returns the public IPv4; they are tried in order when a node checks.',
        ],
        'observability__egress_probe_interval_seconds' => [
            'label' => 'Node IP check interval (seconds)',
            'description' => 'Workers check the egress IP at this interval using a separate timer on each node.',
        ],
        'observability__browser_egress_probe_interval_seconds' => [
            'label' => 'Browser IP check interval (seconds)',
            'description' => 'Sends one lightweight request through each browser host at this interval.',
        ],
        'observability__egress_probe_timeout_seconds' => [
            'label' => 'Egress IP check timeout (seconds)',
            'description' => 'Limit for the total time of each IP check, 5 seconds at most.',
        ],
        'observability__egress_changes_stream' => [
            'label' => 'Log stream for egress IP changes',
            'description' => 'OpenObserve stream that receives a record with the old IP, new IP, node and time of the change.',
        ],
        'observability__record_egress_ip' => [
            'label' => 'Record egress IP in crawl data',
            'description' => 'Turn off to keep the node but leave the egress IP empty in attempts, logs and spans.',
        ],
        'stats__enabled' => [
            'label' => 'Enable long-term crawl statistics',
            'description' => 'Stores hourly crawl figures and emits aggregated performance metrics.',
        ],
        'stats__retention_days' => [
            'label' => 'Crawl statistics retention (days)',
            'description' => 'Days to keep hourly crawl figures before automatic cleanup.',
        ],
        'follow_notifications__enabled' => [
            'label' => 'Notify about new movies from followed items',
            'description' => 'Each new movie from a followed performer, maker or series sends one Telegram message with a cover image and an Open button.',
        ],
        'follow_notifications__daily_cap' => [
            'label' => 'Maximum messages per day',
            'description' => 'Beyond this, remaining movies wait until the next day.',
        ],
        'follow_notifications__quiet_from_hour' => [
            'label' => 'Quiet hours from (0 to 23)',
            'description' => 'No messages are sent during quiet hours; they are sent after quiet hours end. If equal to the end hour, quiet hours are off.',
        ],
        'follow_notifications__quiet_to_hour' => [
            'label' => 'Quiet hours until (0 to 23)',
            'description' => 'End hour (exclusive), in the report time zone.',
        ],
        'follow_notifications__lookback_days' => [
            'label' => 'Only notify about movies seen within (days)',
            'description' => 'Movies older than this are not announced.',
        ],
        'library__enabled' => [
            'label' => 'Enable daily library scan',
            'description' => 'Scans the folders below, detects movie codes in file names and matches them to known movies. Nothing is uploaded and no files are changed.',
        ],
        'library__folders' => [
            'label' => 'Folders to scan (one path per line)',
            'description' => 'Absolute paths readable by the machine running JavMeta. Folders must be mounted into the shared PHP environment.',
        ],
        'library__extensions' => [
            'label' => 'Video file extensions',
            'description' => 'Separated by commas or new lines.',
        ],
        'library__scan_hour' => [
            'label' => 'Daily scan hour (0 to 23)',
            'description' => 'In the report time zone. You can scan immediately with php artisan library:scan --force.',
        ],
        'library__hash_kilobytes' => [
            'label' => 'KB from start and end used for the quick hash',
            'description' => 'Quick hash = file size + first part + last part of the file.',
        ],
        'downloads__enabled' => [
            'label' => 'Enable downloads',
            'description' => 'OFF until the owner approves the decision document (JOOXCR-223). Accepts only magnets that JavMeta has stored for a movie; each download is an owner action.',
        ],
        'downloads__qbittorrent_url' => [
            'label' => 'qBittorrent Web UI URL',
            'description' => 'For example http://192.168.2.10:8080 (internal network).',
        ],
        'downloads__qbittorrent_username' => [
            'label' => 'qBittorrent username',
            'description' => 'Account used to sign in to the qBittorrent Web UI.',
        ],
        'downloads__qbittorrent_password' => [
            'label' => 'qBittorrent password',
            'description' => 'Encrypted in the database and never shown again.',
        ],
        'downloads__category' => [
            'label' => 'qBittorrent category',
            'description' => 'Only torrents in this category are listed and managed.',
        ],
        'downloads__save_path' => [
            'label' => 'Save folder',
            'description' => 'Path on the machine running qBittorrent; it is also the folder the file server serves if the JavMeta machine can read it.',
        ],
        'downloads__timeout' => [
            'label' => 'qBittorrent timeout (seconds)',
            'description' => 'Time limit for each request to qBittorrent.',
        ],
        'downloads__link_search_enabled' => [
            'label' => 'Enable on-demand download-link search',
            'description' => 'Allows anyone, including guests, to request a download-link search from a movie page. Turn it off to protect the shared egress address.',
        ],
        'downloads__link_search_cooldown_hours' => [
            'label' => 'Cooldown after no links are found (hours)',
            'description' => 'After a search finds no links, the same movie cannot be searched again until this interval expires.',
        ],
        'downloads__link_search_global_budget_per_hour' => [
            'label' => 'Global search budget per hour',
            'description' => 'Maximum download-link searches across the whole system per hour to protect the shared egress IP.',
        ],
        'downloads__link_search_per_source_budget_per_hour' => [
            'label' => 'Search budget per source per hour',
            'description' => 'Maximum searches sent to one download source per hour.',
        ],
        'downloads__link_search_client_budget_per_hour' => [
            'label' => 'Search budget per client per hour',
            'description' => 'Maximum searches per IP address and session per hour.',
        ],
        'downloads__link_search_timeout_seconds' => [
            'label' => 'Timeout per source (seconds)',
            'description' => 'Short time limit for each download-link search job on one source.',
        ],
        'downloads__link_search_max_sources_per_run' => [
            'label' => 'Maximum sources per search',
            'description' => 'Each search requests this many sources at most, in sequence rather than all at once.',
        ],
        'files__enabled' => [
            'label' => 'Enable file server',
            'description' => 'OFF until the owner approves (JOOXCR-223). Serves downloaded files through signed, time-limited links.',
        ],
        'files__root_folders' => [
            'label' => 'Folders allowed to be served (one path per line)',
            'description' => 'Files outside these folders are never served.',
        ],
        'files__link_ttl_minutes' => [
            'label' => 'Links expire after (minutes)',
            'description' => 'File download links expire automatically after this period.',
        ],
        'files__allowed_networks' => [
            'label' => 'Networks allowed access (CIDR, one network per line)',
            'description' => 'Only addresses in these networks can open the links. The internal network by default.',
        ],
        'domains__ui_host' => [
            'label' => 'XCrawler UI domain',
            'description' => 'UI and API. For reference only: HAProxy and Apache decide the real domain.',
        ],
        'domains__command_center_host' => [
            'label' => 'Command Center domain',
            'description' => 'The operations page (/ops). Signing in on this domain goes straight to /ops; the XCrawler UI points its Operations button here. Leave empty if domains are not split.',
        ],
        'backup__manifest_path' => [
            'label' => 'Backup manifest path',
            'description' => 'State file updated by the backup command when it finishes.',
        ],
        'backup__monitoring_enabled' => [
            'label' => 'Enable backup monitoring',
            'description' => 'Off by default. When on, the system checks the age of the latest backup every minute, sends an alert when it is too old and counts it in the readiness report.',
        ],
        'backup__stale_after_seconds' => [
            'label' => 'Backup considered stale after (seconds)',
            'description' => 'Used for the per-minute backup alert.',
        ],
        'normalization__blood_types' => [
            'label' => 'Valid blood types',
            'description' => 'Standard blood types the normalizer accepts: A, B, O and AB.',
        ],
        'normalization__cups' => [
            'label' => 'Valid cup sizes',
            'description' => 'Standard cup sizes the normalizer accepts, from A to K.',
        ],
        'normalization__junk_tokens' => [
            'label' => 'Junk values in profiles',
            'description' => 'JSON map from profile field to junk or placeholder values; matching ignores case and whitespace.',
        ],
        'normalization__field_ranges' => [
            'label' => 'Profile field value ranges',
            'description' => 'JSON minimum and maximum limits for measurements in centimeters, birth date and debut date; the maximum of a date can be today.',
        ],
        'normalization__location_aliases' => [
            'label' => 'Japanese location names and aliases',
            'description' => 'JSON map from Japanese prefectures, cities and aliases to canonical location names; aliases can be added or edited here.',
        ],
        'normalization__backfill_chunk_size' => [
            'label' => 'Backfill batch size',
            'description' => 'Number of performer profiles processed before each resume marker; can be overridden with the --chunk option.',
        ],
    ],
    'validation' => [
        'queue_worker_timeout' => 'queue.worker_timeout must exceed timeouts.fetch_service_seconds.',
        'worker_stop_grace' => 'worker.stop_grace_seconds must be at least queue.worker_timeout.',
        'queue_retry_after' => 'queue.retry_after_seconds must exceed queue.worker_timeout.',
        'browser_gateway_wait' => 'browser.gateway_wait_timeout_ms must exceed the Playwright and FlareSolverr service timeouts.',
        'browser_gateway_client' => 'browser.gateway_client_timeout_ms must exceed browser.gateway_wait_timeout_ms.',
    ],
];

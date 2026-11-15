<?php

declare(strict_types=1);

return [
    'not_found' => 'Notification type not found.',
    'registry' => [
        'crawl__success_rate_low' => [
            'name' => 'Low crawl success rate',
            'usage' => 'Sent when the success rate falls below the threshold.',
        ],
        'crawl__blocked_ratio_high' => [
            'name' => 'High blocked-request ratio',
            'usage' => 'Sent when the blocked-request ratio exceeds the threshold.',
        ],
        'crawl__source_success_low' => [
            'name' => 'Low source success rate',
            'usage' => 'Sent when a source has a low success rate.',
        ],
        'database__connections_high' => [
            'name' => 'High PostgreSQL connections',
            'usage' => 'Sent when PostgreSQL is near its connection limit.',
        ],
        'crawl__dispatch_delayed' => [
            'name' => 'Crawl dispatch delayed',
            'usage' => 'Sent when due work remains undispatched.',
        ],
        'crawl__backlog_by_source_high' => [
            'name' => 'Source queue nearly empty',
            'usage' => 'Sent when a source has very few due items left.',
        ],
        'crawl__heartbeat_stale' => [
            'name' => 'Worker heartbeat lost',
            'usage' => 'Sent when a worker heartbeat is overdue.',
        ],
        'crawl__heartbeat_recovered' => [
            'name' => 'Worker heartbeat recovered',
            'usage' => 'Sent once when the heartbeat is healthy again.',
        ],
        'crawl__source_stale' => [
            'name' => 'Stale crawl source',
            'usage' => 'Sent when a source misses its configured success schedule.',
        ],
        'crawl__source_recovered' => [
            'name' => 'Crawl source recovered',
            'usage' => 'Sent when a source recovers or is no longer watched.',
        ],
        'source__unhealthy' => [
            'name' => 'Unhealthy crawl source',
            'usage' => 'Sent when a crawl health check finds a failing source.',
        ],
        'crawl__stalled' => [
            'name' => 'Crawl stalled',
            'usage' => 'Sent when due work exists but no source succeeds.',
        ],
        'crawl__stalled_recovered' => [
            'name' => 'Crawl resumed',
            'usage' => 'Sent once when crawling is active again.',
        ],
        'crawl__queue_stuck' => [
            'name' => 'Crawl queue stuck',
            'usage' => 'Sent when in-flight items exceed the allowed processing time.',
        ],
        'crawl__queue_recovered' => [
            'name' => 'Crawl queue recovered',
            'usage' => 'Sent once when no stuck items remain.',
        ],
        'crawl__backlog_high' => [
            'name' => 'Crawl backlog too large',
            'usage' => 'Sent when due items exceed the configured threshold.',
        ],
        'crawl__backlog_recovered' => [
            'name' => 'Crawl backlog recovered',
            'usage' => 'Sent once when due items fall below the threshold.',
        ],
        'catalog__hourly' => [
            'name' => 'Hourly catalog report',
            'usage' => 'Summarizes new movies and performers in the past hour.',
        ],
        'catalog__lookup_missing' => [
            'name' => 'Requested catalog data missing',
            'usage' => 'Sent when a requested movie or performer has no data yet.',
        ],
        'source__health_blocked' => [
            'name' => 'Source blocked',
            'usage' => 'Sent when a health check finds a newly blocked source.',
        ],
        'source__health_recovered' => [
            'name' => 'Source recovered',
            'usage' => 'Sent when a source health check confirms recovery.',
        ],
        'staging__readiness_blocked' => [
            'name' => 'Staging not ready',
            'usage' => 'Sent when staging fails startup or readiness checks.',
        ],
        'staging__readiness_recovered' => [
            'name' => 'Staging ready',
            'usage' => 'Sent when staging recovers after a failed state.',
        ],
        'follow__match' => [
            'name' => 'New movie matches a follow',
            'usage' => 'Sent once for each new movie that matches an owner follow.',
        ],
        'backup__manifest_missing' => [
            'name' => 'Backup manifest missing',
            'usage' => 'Sent when no successful backup manifest is available.',
        ],
        'backup__stale' => [
            'name' => 'Backup stale',
            'usage' => 'Sent when the latest backup exceeds the age limit.',
        ],
        'backup__recovered' => [
            'name' => 'Backup recovered',
            'usage' => 'Sent when the latest backup is within the age limit.',
        ],
        'openobserve__telegram_warning' => [
            'name' => 'OpenObserve Telegram warning',
            'usage' => 'Warning text template sent by OpenObserve via Telegram.',
        ],
        'openobserve__telegram_critical' => [
            'name' => 'OpenObserve Telegram critical',
            'usage' => 'Critical text template sent by OpenObserve via Telegram.',
        ],
        'deploy__success' => [
            'name' => 'Deploy succeeded',
            'usage' => 'Used when a deploy finishes across nodes.',
        ],
        'deploy__rolled_back' => [
            'name' => 'Deploy rolled back',
            'usage' => 'Used when a deploy is rolled back.',
        ],
        'node__joined' => [
            'name' => 'Node joined',
            'usage' => 'Used when a new node starts sending heartbeats.',
        ],
        'node__lost' => [
            'name' => 'Node lost',
            'usage' => 'Used when a node stops sending heartbeats.',
        ],
        'horizon__master_missing' => [
            'name' => 'Horizon master missing',
            'usage' => 'Used when no Horizon master is found.',
        ],
        'horizon__supervisor_missing' => [
            'name' => 'Horizon supervisor missing',
            'usage' => 'Used when a required Horizon supervisor is missing.',
        ],
        'queue__failed_jobs_spike' => [
            'name' => 'Failed jobs spike',
            'usage' => 'Used when failed jobs exceed the threshold.',
        ],
        'database__deadlock_burst' => [
            'name' => 'Database deadlock burst',
            'usage' => 'Used when deadlocks exceed the threshold.',
        ],
        'queue__retry_permanent_failures' => [
            'name' => 'Retry channel permanent failures',
            'usage' => 'Used when the retry channel hits non-retryable failures.',
        ],
        'network__egress_ip_changed' => [
            'name' => 'Egress IP changed',
            'usage' => 'Used when a node egress address changes.',
        ],
        'network__egress_ip_shared' => [
            'name' => 'Egress IP shared',
            'usage' => 'Used when multiple nodes share the same egress IP.',
        ],
        'browser__host_saturated' => [
            'name' => 'Browser host saturated',
            'usage' => 'Used when the browser host reaches saturation.',
        ],
        'browser__old_processes' => [
            'name' => 'Stale browser processes',
            'usage' => 'Used when browser processes run longer than allowed.',
        ],
        'flaresolverr__down' => [
            'name' => 'FlareSolverr down',
            'usage' => 'Used when FlareSolverr fails its health check.',
        ],
        'sentry__new_critical_group' => [
            'name' => 'New Sentry critical group',
            'usage' => 'Used when Sentry detects a new critical error group.',
        ],
        'source__exhausted' => [
            'name' => 'Source exhausted',
            'usage' => 'Used when consecutive scans find no new content.',
        ],
        'catalog__daily_summary' => [
            'name' => 'Daily catalog summary',
            'usage' => 'Used for the daily catalog metrics report.',
        ],
        'backup__success' => [
            'name' => 'Backup succeeded',
            'usage' => 'Used after a successful database backup.',
        ],
        'backup__restore_test' => [
            'name' => 'Backup restore test',
            'usage' => 'Used after a restore test completes.',
        ],
        'security__api_key_created' => [
            'name' => 'API key created',
            'usage' => 'Used when an owner creates an API key.',
        ],
        'security__api_key_revoked' => [
            'name' => 'API key revoked',
            'usage' => 'Used when an owner revokes an API key.',
        ],
        'security__login_failures' => [
            'name' => 'Repeated login failures',
            'usage' => 'Used when failed login attempts exceed the threshold.',
        ],
        'security__guest_mode_toggled' => [
            'name' => 'Guest mode changed',
            'usage' => 'Used when guest access is enabled or disabled.',
        ],
        'security__settings_changed' => [
            'name' => 'System settings changed',
            'usage' => 'Used when an owner changes system settings.',
        ],
        'startup_gate__blocked' => [
            'name' => 'Startup gate blocked',
            'usage' => 'Used when the startup gate prevents the service from starting.',
        ],
        'crawl__success_rate_critical' => [
            'name' => 'Critical crawl success rate',
            'usage' => 'Sent when the success rate stays below 70% for 15 minutes.',
        ],
        'crawl__success_rate_low__movies' => [
            'name' => 'Low movie crawl success rate',
            'usage' => 'Warns when movie crawl success is low by data type.',
        ],
        'crawl__success_rate_low__performers' => [
            'name' => 'Low performer crawl success rate',
            'usage' => 'Warns when performer crawl success is low by data type.',
        ],
        'dependency__unreachable' => [
            'name' => 'Data dependency unreachable',
            'usage' => 'Sent when PostgreSQL, Redis, MongoDB, or Elasticsearch is unreachable.',
        ],
        'resource__cpu_high' => [
            'name' => 'High CPU usage',
            'usage' => 'Sent when host CPU exceeds the warning threshold.',
        ],
        'resource__memory_high' => [
            'name' => 'High memory usage',
            'usage' => 'Sent when host memory exceeds the warning threshold.',
        ],
        'resource__disk_high' => [
            'name' => 'Host disk nearly full',
            'usage' => 'Sent when disk usage exceeds the warning threshold; above 90% is raised to critical.',
        ],
        'resource__disk_critical' => [
            'name' => 'Host disk critically full',
            'usage' => 'Sent when disk usage exceeds 90%.',
        ],
        'node__left' => [
            'name' => 'Node left',
            'usage' => 'Sent once when a node leaves the fleet.',
        ],
        'report__periodic' => [
            'name' => 'Periodic status report',
            'usage' => 'Notification template for periodic status reports.',
        ],
        'report__daily' => [
            'name' => 'Daily status report',
            'usage' => 'Notification template for the 24-hour status report.',
        ],
        'deploy__ok' => [
            'name' => 'Deploy completed',
            'usage' => 'One-shot notice when all nodes finish deploying.',
        ],
        'egress__changed' => [
            'name' => 'Egress address changed',
            'usage' => 'Notifies when a node egress address changes.',
        ],
        'egress__shared' => [
            'name' => 'Egress address shared',
            'usage' => 'Notifies when multiple nodes use the same egress address.',
        ],
        'retry__permanent_failures' => [
            'name' => 'Retries exhausted',
            'usage' => 'Daily digest of items that exhausted their retry budget.',
        ],
        'jobs__failed_spike' => [
            'name' => 'Failed jobs spike',
            'usage' => 'Sent when failed jobs exceed the threshold in the window.',
        ],
        'jobs__deadlock_burst' => [
            'name' => 'Deadlock burst',
            'usage' => 'Sent when deadlocks exceed the threshold in the window.',
        ],
        'browser__saturated' => [
            'name' => 'Browser host saturated',
            'usage' => 'Sent when browser slots are full and work is waiting.',
        ],
        'browser__down' => [
            'name' => 'Browser service down',
            'usage' => 'Sent when the Playwright or FlareSolverr endpoint fails repeatedly.',
        ],
        'sentry__new_critical' => [
            'name' => 'New Sentry issue',
            'usage' => 'Notifies when Sentry detects a new error group.',
        ],
        'backup__ok' => [
            'name' => 'Backup succeeded',
            'usage' => 'Notifies after a backup job completes.',
        ],
        'startup__blocked' => [
            'name' => 'Startup gate blocked',
            'usage' => 'Sent when a critical dependency prevents the service from starting.',
        ],
        'security__setting_changed' => [
            'name' => 'System setting changed',
            'usage' => 'Sent when an owner changes a non-secret setting.',
        ],
    ],
];

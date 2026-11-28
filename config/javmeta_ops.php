<?php

declare(strict_types=1);

/*
| The Command Center infrastructure page (GET /api/v2/infrastructure): where Prometheus is, what each VM is for and which
| dependencies are probed. With no Prometheus URL the page answers with empty lists and meta.degraded = true.
*/
return [
    'prometheus_url' => null,
    'cache_seconds' => 20,
    'timeout_seconds' => 5,
    // How long one /events/stream connection stays open before the client reconnects.
    'stream_seconds' => 300,
    // vm_name label of the node exporter => what the VM is for, and the group the page draws it in.
    'vm_roles' => [
        'gatekeeper' => ['role' => 'Edge gateway (HAProxy)', 'group' => 'edge'],
        'monitor' => ['role' => 'Monitoring (Prometheus, Loki, Alertmanager)', 'group' => 'ops'],
        'postgres' => ['role' => 'PostgreSQL', 'group' => 'data'],
        'mongodb' => ['role' => 'MongoDB', 'group' => 'data'],
        'redis' => ['role' => 'Redis', 'group' => 'data'],
        'elasticsearch' => ['role' => 'Elasticsearch', 'group' => 'data'],
        'sentry' => ['role' => 'Sentry', 'group' => 'ops'],
        'openobserve' => ['role' => 'OpenObserve', 'group' => 'ops'],
        'vm-tools' => ['role' => 'Fetch browsers (FlareSolverr, Playwright), SonarQube', 'group' => 'browser'],
        'xcrawler' => ['role' => 'Crawler main node', 'group' => 'app'],
        'xcrawler-worker-1' => ['role' => 'Crawler worker 1', 'group' => 'app'],
        'xcrawler-worker-2' => ['role' => 'Crawler worker 2', 'group' => 'app'],
    ],
    // Probes run from this server: name => ['http' => url] or ['tcp' => 'host:port'].
    'probes' => [],
];

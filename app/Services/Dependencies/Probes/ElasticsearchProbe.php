<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use App\Services\Search\ElasticsearchHttpClient;
use Throwable;

final class ElasticsearchProbe implements DetailedDependencyProbe
{
    public function __construct(private readonly ElasticsearchHttpClient $elasticsearch) {}

    public function probe(): bool
    {
        return $this->check()->available !== false;
    }

    public function check(): ProbeResult
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return new ProbeResult(null, 'Elasticsearch is disabled.');
        }

        try {
            $available = $this->elasticsearch->get('probe', '/_cluster/health', (int) config('javmeta_health.startup.timeout_seconds', 2), connectTimeout: 1)->successful();

            return new ProbeResult($available, $available ? 'Cluster health endpoint responded.' : 'Cluster health endpoint failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Cluster health endpoint failed.');
        }
    }
}

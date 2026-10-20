<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DependencyProbe;
use App\Services\Search\ElasticsearchHttpClient;
use Throwable;

final class ElasticsearchProbe implements DependencyProbe
{
    public function __construct(private readonly ElasticsearchHttpClient $elasticsearch) {}

    public function probe(): bool
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return true;
        }

        try {
            return $this->elasticsearch->get('probe', '/_cluster/health', 2, connectTimeout: 1)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}

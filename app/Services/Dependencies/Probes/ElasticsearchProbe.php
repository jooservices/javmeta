<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DependencyProbe;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ElasticsearchProbe implements DependencyProbe
{
    public function probe(): bool
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return true;
        }

        $host = rtrim((string) config('elasticsearch.host', 'http://elasticsearch:9200'), '/');

        try {
            $request = Http::connectTimeout(1)->timeout(2);
            if (! (bool) config('elasticsearch.verify_ssl', false)) {
                $request = $request->withoutVerifying();
            }

            return $request->get($host . '/_cluster/health')->successful();
        } catch (Throwable) {
            return false;
        }
    }
}

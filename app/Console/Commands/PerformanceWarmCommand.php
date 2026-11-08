<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Performance\PerformanceCache;
use Illuminate\Console\Command;
use Throwable;

final class PerformanceWarmCommand extends Command
{
    protected $signature = 'perf:warm {--status-only : Refresh the 15-second status cache only}';

    protected $description = 'Refresh status and whole-catalog movie facets before their caches expire';

    public function handle(PerformanceCache $cache): int
    {
        if (! (bool) config('javmeta_perf.warm_enabled', true)) {
            $this->info('Performance cache warming is disabled.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach ($this->option('status-only') ? ['summary'] : ['summary', 'facets'] as $method) {
            try {
                $method === 'summary' ? $cache->summary(refresh: true) : $cache->facets(refresh: true);
                $this->info($method . ' cache refreshed.');
            } catch (Throwable) {
                // Do not expose database connection details through scheduler output.
                $this->error($method . ' cache refresh failed.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

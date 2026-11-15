<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Performance\PerformanceCache;
use Illuminate\Console\Command;
use Throwable;

final class PerformanceWarmCommand extends Command
{
    protected $signature = 'perf:warm {--status-only : No-op because status summaries are computed directly}';

    protected $description = 'Refresh whole-catalog movie facets before their cache expires';

    public function handle(PerformanceCache $cache): int
    {
        if (! (bool) config('javmeta_perf.warm_enabled', true)) {
            $this->info('Performance cache warming is disabled.');

            return self::SUCCESS;
        }
        if ($this->option('status-only')) {
            $this->info('Status summaries are computed directly; there is no status cache to warm.');

            return self::SUCCESS;
        }

        try {
            $cache->facets(refresh: true);
            $this->info('facets cache refreshed.');
        } catch (Throwable) {
            // Do not expose database connection details through scheduler output.
            $this->error('facets cache refresh failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

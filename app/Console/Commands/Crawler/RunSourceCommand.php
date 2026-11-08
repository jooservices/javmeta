<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use Illuminate\Console\Command;

/** Enqueue listing seeds for one site (or all) then optionally dispatch. */
final class RunSourceCommand extends Command
{
    protected $signature = 'crawler:run-source {slug?} {--dispatch : Also run crawler:feed-pool after seed} {--limit=20}';

    protected $aliases = ['crawl:source'];

    protected $description = 'Enqueue crawl listings for one source slug (or all when omitted).';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $limit = max(1, (int) $this->option('limit'));

        $params = ['--limit' => $limit];
        if (is_string($slug) && $slug !== '') {
            $params['--source'] = $slug;
        }

        $code = $this->call('crawler:sync-sources');
        if ($code !== self::SUCCESS) {
            return $code;
        }

        $code = $this->call('crawler:reclaim');
        if ($code !== self::SUCCESS) {
            return $code;
        }

        $code = $this->call('crawler:seed', $params);
        if ($code !== self::SUCCESS) {
            return $code;
        }

        if ($this->option('dispatch')) {
            return $this->call('crawler:feed-pool', ['--limit' => $limit]);
        }

        return self::SUCCESS;
    }
}

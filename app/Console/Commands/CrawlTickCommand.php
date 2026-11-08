<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class CrawlTickCommand extends Command
{
    protected $signature = 'crawl:tick {--source=} {--limit=50}';

    protected $description = 'Enqueue crawl listing URLs for enabled JAVMeta sources.';

    public function handle(): int
    {
        $this->components->warn('This command is deprecated. Use crawler:sync-sources, crawler:reclaim, and crawler:seed instead.');

        $source = $this->option('source');
        $seedOptions = ['--limit' => $this->option('limit')];
        if (is_string($source) && $source !== '') {
            $seedOptions['--source'] = $source;
        }

        if ($this->call('crawler:sync-sources') !== self::SUCCESS) {
            return self::FAILURE;
        }

        if ($this->call('crawler:reclaim') !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('crawler:seed', $seedOptions);
    }
}

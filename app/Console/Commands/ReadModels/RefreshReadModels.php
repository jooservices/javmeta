<?php

declare(strict_types=1);

namespace App\Console\Commands\ReadModels;

use App\Repositories\ReadModels\ReadModelRefresher;
use Illuminate\Console\Command;
use RuntimeException;

final class RefreshReadModels extends Command
{
    protected $signature = 'xcrawler:read-models:refresh';

    protected $description = 'Rebuild the search tables of the UI API from the catalogue (atomic swap, one run at a time)';

    public function handle(ReadModelRefresher $refresher): int
    {
        try {
            $rows = $refresher->refresh();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($rows as $table => $count) {
            $this->line("{$table}: {$count} rows");
        }

        return self::SUCCESS;
    }
}

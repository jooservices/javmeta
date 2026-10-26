<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dataset\SampleDataset;
use App\Services\Dataset\DatasetGuard;
use RuntimeException;
use Illuminate\Console\Command;

final class DatasetWipeCommand extends Command
{
    protected $signature = 'dataset:wipe {name : Only "sample" exists} {--force : Confirm an allow-listed non-testing database}';

    protected $description = 'Remove exactly the rows that dataset:load sample created (explicit ownership markers).';

    public function handle(SampleDataset $dataset, DatasetGuard $guard): int
    {
        if ($this->argument('name') !== 'sample') {
            $this->components->error('Only the "sample" dataset exists.');

            return self::FAILURE;
        }

        try {
            $guard->check((bool) $this->option('force'));
            $dataset->wipe();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->components->info('Sample dataset removed.');

        return self::SUCCESS;
    }
}

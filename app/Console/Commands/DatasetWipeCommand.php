<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dataset\SampleDataset;
use Illuminate\Console\Command;

final class DatasetWipeCommand extends Command
{
    protected $signature = 'dataset:wipe {name : Only "sample" exists}';

    protected $description = 'Remove exactly the rows that dataset:load sample created (movie codes SMP-nnnn, performer source "sample", genres "sample-nn").';

    public function handle(SampleDataset $dataset): int
    {
        if ($this->argument('name') !== 'sample') {
            $this->components->error('Only the "sample" dataset exists.');

            return self::FAILURE;
        }

        $dataset->wipe();
        $this->components->info('Sample dataset removed.');

        return self::SUCCESS;
    }
}

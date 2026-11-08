<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dataset\SampleDataset;
use App\Services\Dataset\DatasetGuard;
use RuntimeException;
use Illuminate\Console\Command;

final class DatasetLoadCommand extends Command
{
    protected $signature = 'dataset:load {name : Only "sample" exists} {--force : Confirm an allow-listed non-testing database} {--index : Also index the rows in Elasticsearch}';

    protected $description = 'Load the repeatable fake sample dataset (520 movies, 210 performers, 30 genres, 20 makers, 10 series). Safe to run twice.';

    public function handle(SampleDataset $dataset, DatasetGuard $guard): int
    {
        if ($this->argument('name') !== 'sample') {
            $this->components->error('Only the "sample" dataset exists.');

            return self::FAILURE;
        }

        try {
            $guard->check((bool) $this->option('force'));
            $result = $dataset->load((bool) $this->option('index'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->components->info(sprintf('Sample dataset: %d movies, %d performers, %d genres, %d makers, %d series; %d rows indexed.', $result['movies'], $result['performers'], $result['genres'], $result['makers'], $result['series'], $result['indexed']));

        return self::SUCCESS;
    }
}

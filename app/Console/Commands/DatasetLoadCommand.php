<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Dataset\SampleDataset;
use Illuminate\Console\Command;

final class DatasetLoadCommand extends Command
{
    protected $signature = 'dataset:load {name : Only "sample" exists} {--index : Also index the rows in Elasticsearch}';

    protected $description = 'Load the repeatable fake sample dataset (520 movies, 210 performers, 30 genres, 20 makers, 10 series). Safe to run twice.';

    public function handle(SampleDataset $dataset): int
    {
        if ($this->argument('name') !== 'sample') {
            $this->components->error('Only the "sample" dataset exists.');

            return self::FAILURE;
        }

        $result = $dataset->load((bool) $this->option('index'));
        $this->components->info(sprintf('Sample dataset: %d movies, %d performers, %d genres, %d makers, %d series; %d rows indexed.', $result['movies'], $result['performers'], $result['genres'], $result['makers'], $result['series'], $result['indexed']));

        return self::SUCCESS;
    }
}

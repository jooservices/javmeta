<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Report\NodeSampler;
use Illuminate\Console\Command;
use Throwable;

final class NodeSampleCommand extends Command
{
    protected $signature = 'node:sample';

    protected $description = 'Record this node’s CPU, memory, disk, and Horizon worker sample.';

    public function handle(NodeSampler $sampler): int
    {
        try {
            $sample = $sampler->sample();
        } catch (Throwable) {
            $this->components->error('This node sample could not be stored.');

            return self::FAILURE;
        }

        $this->components->info('Stored node sample for ' . (string) ($sample['node'] ?? 'unknown') . '.');
        $this->line((string) json_encode($sample, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

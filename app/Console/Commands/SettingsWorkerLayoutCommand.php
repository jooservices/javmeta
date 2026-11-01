<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use App\Services\Workers\HorizonWorkerLayout;
use Illuminate\Console\Command;

final class SettingsWorkerLayoutCommand extends Command
{
    protected $signature = 'settings:worker-layout {--json : Emit the effective worker settings as JSON} {--shell : Emit tab-separated values for the legacy supervisor}';

    protected $description = 'Print the effective database-backed worker layout for the legacy database queue supervisor.';

    public function handle(SettingsService $settings, HorizonWorkerLayout $layout): int
    {
        $settings->apply();
        $snapshot = $layout->snapshot();
        $queues = [];
        foreach ($snapshot['supervisors'] as $supervisor) {
            $queues[$supervisor['queue'][0]] = $supervisor['maxProcesses'];
        }
        ksort($queues);
        $first = reset($snapshot['supervisors']);
        $values = [
            'queues' => $queues,
            'sleep' => (int) config('javmeta_queues.queue_sleep_seconds'),
            'tries' => (int) $first['tries'],
            'max_time' => (int) $first['maxTime'],
            'max_jobs' => (int) $first['maxJobs'],
            'timeout' => (int) $first['timeout'],
            'stop_grace_seconds' => (int) config('javmeta_queues.stop_grace_seconds'),
        ];
        if ((bool) $this->option('shell')) {
            foreach ($values as $name => $value) {
                if ($name === 'queues') {
                    foreach ($value as $queue => $count) {
                        $this->output->writeln("QUEUE\t{$queue}\t{$count}");
                    }
                    continue;
                }
                $this->output->writeln("SETTING\t{$name}\t{$value}");
            }

            return self::SUCCESS;
        }
        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Queue', 'Workers'], array_map(static fn(string $queue, int $workers): array => [$queue, $workers], array_keys($queues), $queues));

        return self::SUCCESS;
    }
}

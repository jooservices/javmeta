<?php

declare(strict_types=1);

namespace App\Services\Report;

final class ProcWorkerProcessSource implements WorkerProcessSource
{
    public function __construct(private readonly string $procPath = '/proc') {}

    /** @return list<array{pid: int, lane: string}> */
    public function processes(): array
    {
        $processes = [];
        foreach (glob(rtrim($this->procPath, '/') . '/[0-9]*/cmdline') ?: [] as $file) {
            $command = @file_get_contents($file);
            if (! is_string($command) || $command === '') {
                continue;
            }
            $command = str_replace("\0", ' ', $command);
            if (! str_contains($command, 'horizon:work')) {
                continue;
            }

            $pid = (int) basename(dirname($file));
            if ($pid > 0) {
                $processes[] = ['pid' => $pid, 'lane' => self::lane($command)];
            }
        }

        return $processes;
    }

    private static function lane(string $command): string
    {
        preg_match('/(?:^|\s)--queue(?:=|\s+)([^\s]+)/', $command, $matches);
        $queue = (string) ($matches[1] ?? '');
        $browserQueues = array_values((array) config('javmeta_queues.browser_names', []));
        foreach (explode(',', $queue) as $name) {
            if (in_array($name, $browserQueues, true) || str_starts_with($name, 'browser_')) {
                return 'browser';
            }
        }

        return 'direct';
    }
}

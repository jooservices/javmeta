<?php

declare(strict_types=1);

namespace App\Services\Report;

use Illuminate\Support\Str;

final class WorkerLane
{
    public static function fromQueue(string $queue): string
    {
        $queue = Str::after($queue, ':');
        foreach ((array) config('javmeta_queues.browser_names', []) as $browserQueue) {
            if ($queue === $browserQueue || str_contains($queue, (string) $browserQueue)) {
                return 'browser';
            }
        }

        return 'direct';
    }
}

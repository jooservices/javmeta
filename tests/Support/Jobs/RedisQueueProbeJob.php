<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Redis;

final class RedisQueueProbeJob implements ShouldQueue
{
    public function __construct(
        public readonly string $id,
        public readonly string $completionKey,
    ) {}

    public function handle(): void
    {
        usleep(10_000);
        Redis::connection('default')->rpush($this->completionKey, getmypid() . ':' . $this->id);
    }
}

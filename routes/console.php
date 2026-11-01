<?php

declare(strict_types=1);

use App\Services\Health\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (! app()->runningUnitTests()) {
    Schedule::call(static function (): void {
        app(SchedulerHeartbeat::class)->beat();
    })->everyMinute()->name('javmeta:scheduler-heartbeat');

    Schedule::command('crawler:sync-sources')->everyFifteenMinutes();
    Schedule::command('crawler:reclaim')->everyMinute();
    Schedule::command('crawler:seed')->everyMinute();
    $feedInterval = (int) config('javmeta_queues.feed_interval_seconds', 10);
    if (! in_array($feedInterval, [5, 10, 15, 30, 60], true)) {
        $feedInterval = 10;
    }
    $feedPool = Schedule::command('crawler:feed-pool')->withoutOverlapping();
    match ($feedInterval) {
        5 => $feedPool->everyFiveSeconds(),
        10 => $feedPool->everyTenSeconds(),
        15 => $feedPool->everyFifteenSeconds(),
        30 => $feedPool->everyThirtySeconds(),
        60 => $feedPool->everyMinute(),
    };
    Schedule::command('obs:publish-metrics')->everyMinute();
    Schedule::command('javmeta:backup-status', ['--lenient'])->everyMinute();
    Schedule::command('crawler:recrawl-incomplete')->hourly()->withoutOverlapping();
    Schedule::command('search:backfill-embeddings')->hourly()->withoutOverlapping();
    Schedule::command('crawler:watchdog')->everyFiveMinutes();
    Schedule::command('crawler:prune-fetch-attempts')->daily()->withoutOverlapping();
    Schedule::command('crawler:source-health')->dailyAt('04:00')->withoutOverlapping(120)->runInBackground();
    Schedule::command('staging:readiness')->hourly()->withoutOverlapping();
    Schedule::command('telegram:poll')->everyMinute()->withoutOverlapping();
    Schedule::command('library:scan')->hourly()->withoutOverlapping();
    Schedule::command('ai:warm')->everyFifteenMinutes()->withoutOverlapping();
    Schedule::command('perf:warm')->everyMinute()->withoutOverlapping();
    // A once-a-minute refresh alone leaves a 45-second hole in the 15-second status cache.
    Schedule::command('perf:warm', ['--status-only'])->everyTenSeconds()->withoutOverlapping();
    Schedule::command('follows:notify')->everyFiveMinutes()->withoutOverlapping();
    Schedule::command('javmeta:hourly-report')->hourly()->withoutOverlapping();
}

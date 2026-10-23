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
    Schedule::command('crawler:feed-pool')->everyMinute()->withoutOverlapping();
    Schedule::command('obs:publish-metrics')->everyMinute();
    Schedule::command('javmeta:backup-status', ['--lenient'])->everyMinute();
    Schedule::command('crawler:recrawl-incomplete')->hourly()->withoutOverlapping();
    Schedule::command('search:backfill-embeddings')->hourly()->withoutOverlapping();
    Schedule::command('crawler:watchdog')->everyFiveMinutes();
    Schedule::command('javmeta:hourly-report')->hourly()->withoutOverlapping();
}

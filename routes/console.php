<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (! app()->runningUnitTests()) {
    Schedule::command('crawler:sync-sources')->everyFifteenMinutes();
    Schedule::command('crawler:reclaim')->everyMinute();
    Schedule::command('crawler:seed')->everyMinute();
    Schedule::command('crawler:feed-pool')->everyMinute()->withoutOverlapping();
    Schedule::command('obs:publish-metrics')->everyMinute();
    Schedule::command('javmeta:backup-status')->everyMinute();
    Schedule::command('crawler:watchdog')->everyFiveMinutes();
}

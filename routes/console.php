<?php

declare(strict_types=1);

use App\Services\Health\SchedulerHeartbeat;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('report:send')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure(static function (): void {
        Log::error('Scheduled command failed.', ['command' => 'report:send']);
    });

$settings = empty(config('app.key')) ? null : app(SettingsService::class);

Schedule::command('report:send-daily')
    ->dailyAt((string) ($settings?->get('report.daily_time') ?? config('javmeta_report.daily_time', '08:00')))
    ->timezone((string) ($settings?->get('report.daily_timezone') ?? config('javmeta_report.daily_timezone', 'America/Mexico_City')))
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure(static function (): void {
        Log::error('Scheduled command failed.', ['command' => 'report:send-daily']);
    });

if ((bool) ($settings?->get('report.legacy_hourly_enabled') ?? config('javmeta_report.legacy_hourly_enabled', false))
    && ! (bool) ($settings?->get('report.enabled') ?? config('javmeta_report.enabled', true))) {
    Schedule::command('javmeta:hourly-report')->hourly()->withoutOverlapping()
        ->onFailure(static function (): void {
            Log::error('Scheduled command failed.', ['command' => 'javmeta:hourly-report']);
        });
}

if (! app()->runningUnitTests()) {
    $scheduleCommand = static function (string $command, array $parameters = []): Event {
        $commandName = explode(' ', trim($command), 2)[0];

        return Schedule::command($command, $parameters)
            ->onFailure(static function () use ($commandName): void {
                Log::error('Scheduled command failed.', ['command' => $commandName]);
            });
    };

    Schedule::call(static function (): void {
        app(SchedulerHeartbeat::class)->beat();
    })->everyMinute()->name('javmeta:scheduler-heartbeat');

    $scheduleCommand('crawler:sync-sources')->everyFifteenMinutes();
    $scheduleCommand('crawler:reclaim')->everyMinute();
    $scheduleCommand('crawler:seed')->everyMinute();
    $scheduleCommand('crawler:feed-pool --scheduled')->everyFiveSeconds()->withoutOverlapping();
    $scheduleCommand('horizon:reconcile-workers')->everyMinute()->withoutOverlapping();
    $scheduleCommand('node:egress-probe')->everyTwoMinutes()->withoutOverlapping();
    $scheduleCommand('obs:publish-metrics')->everyMinute();
    $scheduleCommand('alerts:evaluate')->everyMinute()->withoutOverlapping();
    $scheduleCommand('node:sample')->everyMinute();
    $scheduleCommand('javmeta:backup-status', ['--lenient'])->everyMinute();
    $scheduleCommand('crawler:recrawl-incomplete')->hourly()->withoutOverlapping();
    $scheduleCommand('search:backfill-embeddings')->hourly()->withoutOverlapping();
    $scheduleCommand('crawler:watchdog')->everyFiveMinutes();
    $scheduleCommand('crawler:prune-fetch-attempts')->daily()->withoutOverlapping();
    $scheduleCommand('crawler:stats-rollup')->hourly()->withoutOverlapping();
    $scheduleCommand('crawler:source-health')->dailyAt('04:00')->withoutOverlapping(120)->runInBackground();
    $scheduleCommand('staging:readiness')->hourly()->withoutOverlapping();
    $scheduleCommand('telegram:poll')->everyMinute()->withoutOverlapping();
    $scheduleCommand('library:scan')->hourly()->withoutOverlapping();
    $scheduleCommand('ai:warm')->everyFifteenMinutes()->withoutOverlapping();
    $scheduleCommand('perf:warm')->everyMinute()->withoutOverlapping();
    // A once-a-minute refresh alone leaves a 45-second hole in the 15-second status cache.
    $scheduleCommand('perf:warm', ['--status-only'])->everyTenSeconds()->withoutOverlapping();
    $scheduleCommand('follows:notify')->everyFiveMinutes()->withoutOverlapping();
}

<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class ReportSchedule
{
    public function dueSlot(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if (! (bool) config('javmeta_report.enabled', true)) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $timezone = (string) config('javmeta_report.timezone', 'America/Mexico_City');
        $localNow = $now->setTimezone($timezone);
        $interval = max(15, (int) config('javmeta_report.interval_minutes', 120));
        $minutes = ((int) $localNow->format('H') * 60) + (int) $localNow->format('i');
        $slot = $localNow->startOfDay()->addMinutes(intdiv($minutes, $interval) * $interval);
        if (! $localNow->startOfMinute()->equalTo($slot)) {
            return null;
        }
        if ($this->quiet($localNow)) {
            Cache::add('javmeta:report:quiet-slot:' . $slot->utc()->timestamp, 1, now()->addDays(2));

            return null;
        }
        if (Cache::has('javmeta:report:quiet-slot:' . $slot->utc()->timestamp)) {
            return null;
        }

        $lastScheduled = ReportSnapshot::query()->where('report_type', 'periodic')->whereNotNull('scheduled_for')->max('scheduled_for');
        if (is_string($lastScheduled) && CarbonImmutable::parse($lastScheduled, 'UTC')->addMinutes($interval)->greaterThan($slot->utc())) {
            return null;
        }

        return $slot;
    }

    private function quiet(CarbonImmutable $at): bool
    {
        $start = trim((string) config('javmeta_report.quiet_hours_start', ''));
        $end = trim((string) config('javmeta_report.quiet_hours_end', ''));
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }
        $time = $at->format('H:i');

        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }
}

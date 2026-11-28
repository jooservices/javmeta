<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Alert events and the review queue of titles that keep failing. */
final class AlertFlagRepository
{
    private const ALERT_COLUMNS = ['id', 'rule', 'severity', 'value', 'threshold', 'started_at', 'resolved_at', 'notified_at'];

    private const FLAG_COLUMNS = ['id', 'code_normalized', 'source_slug', 'consecutive_failures', 'reason', 'flagged_at', 'resolved_at'];

    /** @return list<stdClass> */
    public function latestAlerts(int $limit, int $offset = 0, bool $activeOnly = false): array
    {
        return DB::table('alert_events')
            ->when($activeOnly, static fn(Builder $q): Builder => $q->whereNull('resolved_at'))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset)
            ->get(self::ALERT_COLUMNS)
            ->all();
    }

    public function alertCount(bool $activeOnly): int
    {
        return DB::table('alert_events')->when($activeOnly, static fn(Builder $q): Builder => $q->whereNull('resolved_at'))->count();
    }

    /** @return list<stdClass> */
    public function flags(bool $resolved, int $limit, int $offset): array
    {
        return $this->flagState($resolved)->orderByDesc('flagged_at')->orderByDesc('id')->limit($limit)->offset($offset)->get(self::FLAG_COLUMNS)->all();
    }

    public function flagCount(bool $resolved): int
    {
        return $this->flagState($resolved)->count();
    }

    /** Marks an open flag resolved; returns false when there is no flag with that id. */
    public function resolveFlag(int $id): bool
    {
        if (! DB::table('review_flags')->where('id', $id)->exists()) {
            return false;
        }

        DB::table('review_flags')->where('id', $id)->whereNull('resolved_at')->update(['resolved_at' => now()]);

        return true;
    }

    private function flagState(bool $resolved): Builder
    {
        $query = DB::table('review_flags');

        return $resolved ? $query->whereNotNull('resolved_at') : $query->whereNull('resolved_at');
    }
}

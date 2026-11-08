<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\NotificationTemplate;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;
use RuntimeException;

/** Persistence operations for notification policy state and digest queries. */
final class NotificationPolicyServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(NotificationTemplate $model)
    {
        parent::__construct($model);
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed>
     */
    public function touchCause(string $key, string $causeHash, array $context, array $safeContext): array
    {
        if (! Schema::hasTable('notification_policy_events')) {
            throw new RuntimeException('The notification policy state table is unavailable.');
        }

        $now = now();
        DB::table('notification_policy_events')->insertOrIgnore([
            'notification_key' => $key,
            'cause_hash' => $causeHash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_sent_at' => null,
            'critical_sent_at' => null,
            'escalated_at' => null,
            'resolved_at' => null,
            'value' => is_numeric($context['value'] ?? null) ? $context['value'] : null,
            'threshold' => is_numeric($context['threshold'] ?? null) ? $context['threshold'] : null,
            'context' => json_encode($safeContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $current = DB::table('notification_policy_events')
            ->where('notification_key', $key)->where('cause_hash', $causeHash)->first();
        if ($current === null) {
            throw new RuntimeException('The notification policy cause could not be loaded.');
        }
        $update = [
            'last_seen_at' => $now,
            'value' => is_numeric($context['value'] ?? null) ? $context['value'] : $current->value,
            'threshold' => is_numeric($context['threshold'] ?? null) ? $context['threshold'] : $current->threshold,
            'context' => json_encode($safeContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];
        if ($current->resolved_at !== null) {
            $update += [
                'first_seen_at' => $now,
                'critical_sent_at' => null,
                'escalated_at' => null,
                'resolved_at' => null,
                'notice_sent_at' => null,
                'reserved_at' => null,
                'reserved_tier' => null,
            ];
        } elseif (($context['notification_occurrence'] ?? false) === true) {
            return (array) $current;
        }
        DB::table('notification_policy_events')->where('id', $current->id)->update($update);

        $updated = DB::table('notification_policy_events')->where('id', $current->id)->first();

        return $updated === null ? throw new RuntimeException('The notification policy cause could not be reloaded.') : (array) $updated;
    }

    /** @return array<string, mixed>|null */
    public function event(string $key, string $causeHash): ?array
    {
        if (! Schema::hasTable('notification_policy_events')) {
            return null;
        }
        $row = DB::table('notification_policy_events')->where('notification_key', $key)->where('cause_hash', $causeHash)->first();

        return $row === null ? null : (array) $row;
    }

    public function reserve(string $key, string $causeHash, string $tier): bool
    {
        return DB::table('notification_policy_events')
            ->where('notification_key', $key)->where('cause_hash', $causeHash)
            ->where(static fn($query) => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', now()->subMinutes(5)))
            ->update(['reserved_at' => now(), 'reserved_tier' => $tier, 'updated_at' => now()]) > 0;
    }

    public function release(string $key, string $causeHash): void
    {
        DB::table('notification_policy_events')->where('notification_key', $key)->where('cause_hash', $causeHash)
            ->update(['reserved_at' => null, 'reserved_tier' => null, 'updated_at' => now()]);
    }

    public function recordSent(string $key, string $causeHash, string $tier, bool $resolved): void
    {
        $now = now();
        $update = ['last_sent_at' => $now, 'reserved_at' => null, 'reserved_tier' => null, 'updated_at' => $now];
        if ($tier === 'critical' && ! $resolved) {
            $update['critical_sent_at'] = $now;
            $update['escalated_at'] = $now;
        }
        if ($tier === 'notice' && ! $resolved) {
            $update['notice_sent_at'] = $now;
        }
        if ($resolved) {
            $update['resolved_at'] = $now;
        }

        DB::table('notification_policy_events')->where('notification_key', $key)->where('cause_hash', $causeHash)->update($update);
    }

    public function resolveCause(string $key, string $causeHash): void
    {
        DB::table('notification_policy_events')->where('notification_key', $key)->where('cause_hash', $causeHash)
            ->update(['resolved_at' => now(), 'reserved_at' => null, 'reserved_tier' => null, 'updated_at' => now()]);
    }

    /** @return list<array{key: string, name: string, tier: string, opened_at: string, last_seen_at: string, value: int|float|string|null, threshold: int|float|string|null, context: array<string, mixed>}> */
    public function digestItems(bool $daily, ?DateTimeInterface $since = null, bool $warningsOnly = false, ?DateTimeInterface $until = null): array
    {
        if (! Schema::hasTable('notification_policy_events') || ! Schema::hasTable('notification_templates')) {
            return [];
        }
        $includeField = $daily ? 'include_in_daily_report' : 'include_in_periodic_report';
        $query = DB::table('notification_policy_events as event')
            ->join('notification_templates as template', 'template.key', '=', 'event.notification_key')
            ->where('template.enabled', true)
            ->where("template.{$includeField}", true)
            ->whereIn('template.tier', $warningsOnly ? ['warning'] : ['warning', 'info', 'notice'])
            ->whereNull('event.resolved_at')
            ->orderBy('template.tier')->orderBy('event.first_seen_at');
        if ($warningsOnly) {
            $query->whereNull('event.escalated_at');
        }
        if ($since !== null && ! $warningsOnly) {
            $query->where('event.last_seen_at', '>=', $since);
        }
        if ($until !== null && ! $warningsOnly) {
            $query->where('event.last_seen_at', '<=', $until);
        }

        return $query->get([
            'event.notification_key as key', 'template.name', 'template.tier',
            'event.first_seen_at as opened_at', 'event.last_seen_at', 'event.value', 'event.threshold', 'event.context',
        ])->map(static function (object $row): array {
            $context = is_array($row->context) ? $row->context : json_decode((string) $row->context, true);

            return [
                'key' => (string) $row->key,
                'name' => (string) $row->name,
                'tier' => (string) $row->tier,
                'opened_at' => (string) $row->opened_at,
                'last_seen_at' => (string) $row->last_seen_at,
                'value' => $row->value,
                'threshold' => $row->threshold,
                'context' => is_array($context) ? $context : [],
            ];
        })->all();
    }
}

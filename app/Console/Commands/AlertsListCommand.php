<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AlertEvent;
use Illuminate\Console\Command;

final class AlertsListCommand extends Command
{
    protected $signature = 'alerts:list {--hours=24 : Show events started or resolved in this many hours}';

    protected $description = 'List recent alert history.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);
        $events = AlertEvent::query()
            ->where(static function ($query) use ($since): void {
                $query->where('started_at', '>=', $since)
                    ->orWhere('resolved_at', '>=', $since)
                    ->orWhereNull('resolved_at');
            })
            ->orderByRaw('COALESCE(resolved_at, started_at) DESC')
            ->get(['rule', 'severity', 'value', 'threshold', 'started_at', 'resolved_at', 'notified_at']);

        $this->table(
            ['Rule', 'Severity', 'Value', 'Threshold', 'Started', 'Resolved', 'Notified'],
            $events->map(static fn(AlertEvent $event): array => [
                $event->getAttribute('rule'),
                $event->getAttribute('severity'),
                $event->getAttribute('value'),
                $event->getAttribute('threshold'),
                $event->getAttribute('started_at'),
                $event->getAttribute('resolved_at') ?? '—',
                $event->getAttribute('notified_at') ?? '—',
            ])->all(),
        );
        if ($events->isEmpty()) {
            $this->components->info("No alert events in the last {$hours} hour(s).");
        }

        return self::SUCCESS;
    }
}

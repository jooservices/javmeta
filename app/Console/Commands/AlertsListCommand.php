<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\AlertOperationsServiceRepository;
use Illuminate\Console\Command;

final class AlertsListCommand extends Command
{
    protected $signature = 'alerts:list {--hours=24 : Show events started or resolved in this many hours}';

    protected $description = 'List recent alert history.';

    public function __construct(private readonly AlertOperationsServiceRepository $repository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);
        $events = $this->repository->recentEvents($since);

        $this->table(
            ['Rule', 'Severity', 'Value', 'Threshold', 'Started', 'Resolved', 'Notified'],
            array_map(static fn(array $event): array => [
                $event['rule'],
                $event['severity'],
                $event['value'],
                $event['threshold'],
                $event['started_at'],
                $event['resolved_at'] ?? '—',
                $event['notified_at'] ?? '—',
            ], $events),
        );
        if ($events === []) {
            $this->components->info("No alert events in the last {$hours} hour(s).");
        }

        return self::SUCCESS;
    }
}

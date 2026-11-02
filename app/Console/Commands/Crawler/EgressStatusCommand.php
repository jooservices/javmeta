<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Observability\NodeEgressProbe;
use Illuminate\Console\Command;

final class EgressStatusCommand extends Command
{
    protected $signature = 'crawler:egress-status';

    protected $description = 'Show the cached egress IP and change history for crawler nodes and browser hosts.';

    public function handle(NodeEgressProbe $probe): int
    {
        $rows = [];
        foreach ($probe->nodeStatuses() as $status) {
            $rows[] = $this->statusRow('node', $status['node'], $status);
        }
        foreach ($probe->browserStatuses() as $status) {
            $rows[] = $this->statusRow('browser_host', $status['host'], $status);
        }

        $this->table(['Type', 'Node or host', 'Current IP', 'Probe OK', 'Last change (UTC)', 'Changes'], $rows);

        return self::SUCCESS;
    }

    /** @param array{ip: ?string, probe_ok: bool, last_change_at: ?string, change_count: int} $status
     *  @return list<string|int>
     */
    private function statusRow(string $kind, string $identity, array $status): array
    {
        return [
            $kind,
            $identity,
            $status['ip'] ?? '-',
            $status['probe_ok'] ? 'yes' : 'no',
            $status['last_change_at'] ?? '-',
            $status['change_count'],
        ];
    }
}

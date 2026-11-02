<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Observability\NodeEgressProbe;
use Illuminate\Console\Command;

final class NodeEgressProbeCommand extends Command
{
    protected $signature = 'node:egress-probe';

    protected $description = 'Refresh this node and configured browser hosts public egress IP cache when due.';

    public function handle(NodeEgressProbe $probe): int
    {
        $probe->probeNodeIfDue();
        $probe->probeBrowserHostsIfDue();

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Observability\BrowserDnsHealthProbe;
use Illuminate\Console\Command;

final class DnsSsrfCheckCommand extends Command
{
    protected $signature = 'crawler:dns-ssrf-check
                            {--host=* : Hostnames to resolve (defaults to jable/missav canaries)}';

    protected $description = 'Detect DNS answers that leave the browser SSRF guard with no public address to pin.';

    public function handle(BrowserDnsHealthProbe $probe): int
    {
        $hosts = array_values(array_filter($this->option('host'), static fn(mixed $host): bool => is_string($host) && $host !== ''));
        if ($hosts === []) {
            $hosts = ['en.jable.tv', 'missav.ws'];
        }

        $result = $probe->check($hosts);
        $rows = [];
        foreach ($result['hosts'] as $host) {
            $rows[] = [
                $host['host'],
                $host['safe'] ? 'yes' : 'no',
                $host['reason'] ?? '-',
                implode(',', $host['public']) !== '' ? implode(',', $host['public']) : '-',
                implode(',', $host['blocked']) !== '' ? implode(',', $host['blocked']) : '-',
            ];
        }

        $this->table(['Host', 'Safe for SSRF guard', 'Reason', 'Public pins', 'Blocked/decoy'], $rows);

        if (! $result['ok']) {
            $this->error('One or more hosts have no public DNS pin. Fix resolver/DNS filtering on the browser host, then redeploy the CrawlerX SSRF guard that drops decoys.');

            return self::FAILURE;
        }

        $this->info('All checked hosts have at least one public address after decoy filtering.');

        return self::SUCCESS;
    }
}

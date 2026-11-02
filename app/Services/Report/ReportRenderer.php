<?php

declare(strict_types=1);

namespace App\Services\Report;

use Carbon\CarbonImmutable;

final class ReportRenderer
{
    /** @param array<string, mixed> $data */
    public function render(array $data, ?CarbonImmutable $at = null): string
    {
        $at ??= isset($data['generated_at']) ? CarbonImmutable::parse((string) $data['generated_at']) : CarbonImmutable::now();
        $daily = ($data['report_type'] ?? 'periodic') === 'daily';
        $timezone = (string) config($daily ? 'javmeta_report.daily_timezone' : 'javmeta_report.timezone', 'America/Mexico_City');
        $date = $at->setTimezone($timezone);
        $zoneLabel = $timezone === 'America/Mexico_City' ? 'Mexico' : $timezone;
        $minutes = (int) ($data['window_minutes'] ?? 120);
        $window = $minutes % 60 === 0 ? intdiv($minutes, 60) . ' h' : $minutes . ' min';
        $sections = array_values((array) ($data['sections'] ?? ['movies', 'performers', 'crawl', 'sources', 'fleet', 'workers', 'servers', 'services']));
        $crawl = in_array('crawl', $sections, true) ? (array) ($data['crawl'] ?? []) : [];
        $values = [
            '{header}' => '📊 [XCrawler] ' . ($daily ? 'Daily report' : 'Report') . ' · ' . $date->format('M j, H:i') . ' (' . $zoneLabel . ') · last ' . $window,
            '{movies}' => $this->entityBlock('Movies', $data['movies'] ?? null, in_array('movies', $sections, true) ? ($crawl['movies'] ?? null) : null),
            '{performers}' => $this->entityBlock('Performers', $data['performers'] ?? null, in_array('performers', $sections, true) ? ($crawl['performers'] ?? null) : null),
            '{crawl}' => $this->crawlBlock($crawl, $sections),
            '{sources}' => in_array('sources', $sections, true) ? $this->sourcesBlock($data['sources'] ?? []) : '',
            '{fleet}' => in_array('fleet', $sections, true) ? $this->fleetLine($data['fleet'] ?? null) : '',
            '{workers}' => in_array('workers', $sections, true) ? $this->workersBlock($data['node_names'] ?? [], $data['workers'] ?? [], $window) : '',
            '{servers}' => in_array('servers', $sections, true) ? $this->serversBlock($data['node_names'] ?? [], $data['servers'] ?? [], $data['server_extra'] ?? [], $window) : '',
            '{services}' => in_array('services', $sections, true) ? $this->servicesLine($data['services'] ?? []) : '',
            '{warnings}' => $this->warningsBlock($data['warnings'] ?? [], $timezone),
            '{deploys}' => $this->deploysBlock($data['deploys'] ?? [], $timezone),
            '{backup}' => $this->backupLine($data['backup'] ?? [], $timezone),
        ];
        $rendered = strtr((string) config('javmeta_report.template', ''), $values);
        $rendered = preg_replace('/\n{3,}/', "\n\n", $rendered) ?? $rendered;

        return trim($rendered);
    }

    /** @param array<string, array<string, mixed>> $crawl
     *  @param list<string> $sections
     */
    private function crawlBlock(array $crawl, array $sections): string
    {
        $lines = [];
        foreach (['movies' => 'Movies', 'performers' => 'Performers'] as $entity => $label) {
            if (in_array($entity, $sections, true) || ! is_array($crawl[$entity] ?? null)) {
                continue;
            }
            $lines[] = $label . ' crawl   success ' . $this->percent((float) ($crawl[$entity]['success_percent'] ?? 0))
                . ' · blocked ' . $this->percent((float) ($crawl[$entity]['blocked_percent'] ?? 0), 1)
                . ' · ' . number_format((int) ($crawl[$entity]['attempts'] ?? 0)) . ' attempts';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed>|null $entity
     *  @param array<string, mixed>|null $crawl
     */
    private function entityBlock(string $label, ?array $entity, ?array $crawl): string
    {
        if ($entity === null) {
            return '';
        }
        $line = $label . str_repeat(' ', max(1, 14 - strlen($label))) . number_format((int) ($entity['total'] ?? 0))
            . '   (' . $this->change((int) ($entity['since_last'] ?? 0)) . ' since last report · '
            . $this->change((int) ($entity['last_24h'] ?? 0)) . ' in 24 h)';
        $lines = [$line];
        if (isset($entity['avg_per_hour'], $entity['peak_per_hour'])) {
            $lines[] = 'Speed         avg ' . number_format((int) round((float) $entity['avg_per_hour'])) . '/h · peak '
                . number_format((int) round((float) $entity['peak_per_hour'])) . '/h';
        }
        if ($crawl !== null) {
            $lines[] = 'Crawl         success ' . $this->percent((float) ($crawl['success_percent'] ?? 0)) . ' · blocked '
                . $this->percent((float) ($crawl['blocked_percent'] ?? 0), 1) . ' · '
                . number_format((int) ($crawl['attempts'] ?? 0)) . ' attempts';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $sources */
    private function sourcesBlock(array $sources): string
    {
        $lines = [];
        $blocked = array_slice((array) ($sources['blocked'] ?? []), 0, 5);
        $exhausted = array_slice((array) ($sources['exhausted'] ?? []), 0, 5);
        if ($blocked !== []) {
            $lines[] = '⚠️ Blocked sources: ' . implode(', ', $blocked);
        }
        if ($exhausted !== []) {
            $lines[] = '🟡 Exhausted sources: ' . implode(', ', $exhausted);
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed>|null $fleet */
    private function fleetLine(?array $fleet): string
    {
        if ($fleet === null || (int) ($fleet['nodes_running'] ?? 0) === 0) {
            return '';
        }

        return '🖥 ' . (int) $fleet['nodes_running'] . ' nodes running · ' . (int) ($fleet['failed_jobs'] ?? 0) . ' failed jobs';
    }

    /** @param list<string> $nodeNames
     *  @param array<string, array<string, mixed>> $nodes
     */
    private function workersBlock(array $nodeNames, array $nodes, string $window): string
    {
        if ($nodeNames === []) {
            return '';
        }
        $lines = ['Workers (now: busy / idle / total · avg busy over ' . $window . ')'];
        foreach (array_slice($nodeNames, 0, 5) as $name) {
            $row = $nodes[$name] ?? null;
            $workers = is_array($row) && is_array($row['workers'] ?? null) ? $row['workers'] : null;
            if ($workers === null) {
                $lines[] = $name . '   no data';
                continue;
            }
            $average = is_numeric($row['busy_share_avg'] ?? null) ? 'avg ' . (int) round((float) $row['busy_share_avg'] * 100) . '% busy' : 'avg no data';
            $lines[] = $name . '   busy ' . (int) ($workers['busy'] ?? 0) . ' · idle ' . (int) ($workers['idle'] ?? 0)
                . ' · total ' . (int) ($workers['total'] ?? 0) . '   (' . $average . ')';
        }

        $fleet = self::sumLanes($nodes);
        if ($fleet['total'] > 0) {
            $lines[] = 'Fleet      busy ' . $fleet['busy'] . ' · idle ' . $fleet['idle'] . ' · total ' . $fleet['total']
                . '   (direct ' . $fleet['direct']['busy'] . '/' . $fleet['direct']['total'] . ' busy'
                . ' · browser ' . $fleet['browser']['busy'] . '/' . $fleet['browser']['total'] . ' busy)';
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $nodeNames
     *  @param array<string, array<string, mixed>> $nodes
     *  @param array<string, mixed> $services
     */
    private function serversBlock(array $nodeNames, array $nodes, array $services, string $window): string
    {
        $lines = $nodeNames === [] ? [] : ['Servers (avg / peak, last ' . $window . ')'];
        foreach (array_slice($nodeNames, 0, 5) as $name) {
            $row = $nodes[$name] ?? null;
            if (! is_array($row)) {
                $lines[] = $name . '   no data';
                continue;
            }
            $cpu = is_numeric($row['cpu_avg'] ?? null) && is_numeric($row['cpu_peak'] ?? null)
                ? 'CPU ' . $this->percent((float) $row['cpu_avg']) . ' / ' . $this->percent((float) $row['cpu_peak'])
                : 'CPU no data';
            $memory = is_numeric($row['mem_available_bytes'] ?? null)
                ? 'RAM free ' . $this->gigabytes((int) $row['mem_available_bytes']) . ' GB'
                : 'RAM no data';
            $disk = is_numeric($row['disk_used_percent'] ?? null)
                ? 'Disk ' . $this->percent((float) $row['disk_used_percent'])
                : 'Disk no data';
            $lines[] = $name . '   ' . $cpu . '   ' . $memory . '   ' . $disk;
        }
        $browser = is_array($services['browser_stats'] ?? null) ? $services['browser_stats'] : null;
        if ($browser !== null) {
            $cpu = isset($browser['cpu_avg'], $browser['cpu_peak']) ? 'CPU ' . $this->percent((float) $browser['cpu_avg']) . ' / ' . $this->percent((float) $browser['cpu_peak']) : 'CPU no data';
            $memory = isset($browser['mem_available_bytes']) ? 'RAM free ' . $this->gigabytes((int) $browser['mem_available_bytes']) . ' GB' : 'RAM no data';
            $disk = isset($browser['disk_used_percent']) ? 'Disk ' . $this->percent((float) $browser['disk_used_percent']) : 'Disk no data';
            $chrome = isset($browser['chrome_processes']) ? 'Chrome ' . (int) $browser['chrome_processes'] : 'Chrome no data';
            $lines[] = 'browser   ' . $cpu . '   ' . $memory . '   ' . $disk . '   ' . $chrome;
        } elseif (is_string($services['browser'] ?? null)) {
            $lines[] = 'browser   CPU no data   RAM no data   Disk no data   Chrome no data';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $services */
    private function servicesLine(array $services): string
    {
        $parts = [];
        if (is_array($services['postgres'] ?? null)) {
            $parts[] = 'Postgres ' . (int) $services['postgres']['used'] . '/' . (int) $services['postgres']['maximum'] . ' conns';
        }
        if (is_string($services['redis'] ?? null)) {
            $parts[] = 'Redis ' . $services['redis'];
        }
        if (is_string($services['elasticsearch'] ?? null)) {
            $parts[] = 'Elasticsearch ' . $services['elasticsearch'];
        }
        $browser = $services['browser_stats'] ?? null;
        if (is_array($browser) && isset($browser['playwright_busy'], $browser['playwright_total'])) {
            $parts[] = 'Playwright ' . (int) $browser['playwright_busy'] . '/' . (int) $browser['playwright_total'] . ' busy';
        } elseif (is_string($services['browser'] ?? null)) {
            $parts[] = 'Playwright ' . $services['browser'];
        }

        return $parts === [] ? '' : 'Services   ' . implode(' · ', $parts);
    }

    /** @param list<array<string, mixed>> $warnings */
    private function warningsBlock(array $warnings, string $timezone): string
    {
        if ($warnings === []) {
            return 'Warnings   none open';
        }
        $lines = ['Warnings'];
        foreach (array_slice($warnings, 0, 5) as $warning) {
            $openedAt = CarbonImmutable::parse((string) ($warning['opened_at'] ?? 'now'))->setTimezone($timezone)->format('M j, H:i');
            $value = is_numeric($warning['value'] ?? null) ? (string) $warning['value'] : 'n/a';
            $threshold = is_numeric($warning['threshold'] ?? null) ? (string) $warning['threshold'] : 'n/a';
            $lines[] = '⚠️ ' . (string) ($warning['name'] ?? $warning['key'] ?? 'Warning') . ' · open since ' . $openedAt
                . ' · value ' . $value . ' · threshold ' . $threshold;
        }

        return implode("\n", $lines);
    }

    /** @param list<array{at: string, release: string, nodes: string}> $deploys */
    private function deploysBlock(array $deploys, string $timezone): string
    {
        if ($deploys === []) {
            return 'Deploys    none';
        }
        $lines = ['Deploys'];
        foreach (array_slice($deploys, 0, 5) as $deploy) {
            $at = CarbonImmutable::parse($deploy['at'])->setTimezone($timezone)->format('M j, H:i');
            $lines[] = '🚀 ' . $deploy['release'] . ' · ' . $deploy['nodes'] . ' · ' . $at;
        }

        return implode("\n", $lines);
    }

    /** @param array{status?: string, at?: string|null} $backup */
    private function backupLine(array $backup, string $timezone): string
    {
        $at = is_string($backup['at'] ?? null)
            ? CarbonImmutable::parse($backup['at'])->setTimezone($timezone)->format('M j, H:i')
            : 'unknown time';

        return 'Backup     ' . (string) ($backup['status'] ?? 'No recent backup status recorded') . ' · ' . $at;
    }

    /** @param array<string, array<string, mixed>> $nodes
     *  @return array{busy: int, idle: int, total: int, direct: array{busy: int, idle: int, total: int}, browser: array{busy: int, idle: int, total: int}}
     */
    private static function sumLanes(array $nodes): array
    {
        $sum = ['busy' => 0, 'idle' => 0, 'total' => 0, 'direct' => ['busy' => 0, 'idle' => 0, 'total' => 0], 'browser' => ['busy' => 0, 'idle' => 0, 'total' => 0]];
        foreach ($nodes as $node) {
            $workers = is_array($node['workers'] ?? null) ? $node['workers'] : [];
            foreach (['busy', 'idle', 'total'] as $state) {
                $sum[$state] += (int) ($workers[$state] ?? 0);
            }
            foreach (['direct', 'browser'] as $lane) {
                foreach (['busy', 'idle', 'total'] as $state) {
                    $sum[$lane][$state] += (int) ($workers['lanes'][$lane][$state] ?? 0);
                }
            }
        }

        return $sum;
    }

    private function change(int $value): string
    {
        return ($value >= 0 ? '+' : '-') . number_format(abs($value));
    }

    private function percent(float $value, int $precision = 0): string
    {
        $formatted = number_format($value, $precision, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted . '%';
    }

    private function gigabytes(int $bytes): string
    {
        return number_format($bytes / 1_000_000_000, 1, '.', '');
    }
}

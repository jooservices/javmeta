<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Services\Observability\Pruning\PruneApi;
use App\Services\Observability\Pruning\StreamExport;
use App\Services\Observability\Pruning\StreamPolicy;
use App\Services\Observability\Pruning\StreamReferences;
use App\Services\Observability\Pruning\StreamSnapshot;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PruneLegacyStreamsCommand extends Command
{
    protected $signature = 'observability:prune-legacy-streams
        {--prefix= : Exact stream namespace; required}
        {--type=* : metrics or logs; defaults to both}
        {--min-idle-days=14}
        {--export-dir= : New private backup directory}
        {--confirm= : SHA256 printed by a reviewed dry run}
        {--dry-run : Explicit read-only mode}
        {--force : Enable export and deletion}';

    protected $description = 'Export and remove idle, unreferenced legacy OpenObserve streams with fail-closed checks.';

    /** @var list<string> */
    private array $deleted = [];

    /** @var list<string> */
    private array $unverifiedDeletes = [];

    public function handle(PruneApi $api, StreamPolicy $policy, StreamReferences $references, StreamExport $export): int
    {
        $this->deleted = [];
        $this->unverifiedDeletes = [];
        try {
            $prefix = (string) $this->option('prefix');
            $policy->validatePrefix($prefix);
            $types = $this->types();
            $real = (bool) $this->option('force');
            $days = $this->days($real);
            if ($real && ($this->option('dry-run') || ! $this->option('confirm') || ! $this->option('export-dir'))) {
                throw new RuntimeException('Force requires confirm and export-dir; cannot combine force with dry-run.');
            }
            $streams = $api->streams();
            $candidates = $policy->candidates($streams, $prefix, $types, $days);
            $this->line('Organization: ' . (string) config('openobserve.org'));
            $initialProtected = $this->protectedCounts($streams, $policy);
            $this->reportInventory($candidates, $initialProtected);
            if ($candidates === []) {
                $this->error('Nothing to do: empty candidate list; deletion refused.');

                return self::FAILURE;
            }
            $policy->assertIdle($candidates, $days);
            app(StreamSnapshot::class)->verify($candidates);
            $references->check($candidates, fn(string $message) => $this->line($message));
            $hash = $policy->hash($candidates);
            $this->line('Manifest SHA256: ' . $hash);
            if (! $real) {
                $this->info('Dry run: no exports or deletions.');

                return self::SUCCESS;
            }
            if (! hash_equals($hash, (string) $this->option('confirm'))) {
                throw new RuntimeException('Manifest confirmation mismatch; live state changed or wrong hash.');
            }
            $files = $export->export($candidates, (string) $this->option('export-dir'), $hash);
            // Check again after exporting: a writer or reference may have appeared during the backup.
            $current = $api->streams();
            $this->assertManifest($policy, $current, $prefix, $types, $days, $hash);
            $references->check($candidates, fn(string $message) => $this->line($message));
            $before = $this->protectedCounts($current, $policy);
            $this->assertProtected($initialProtected, $before);
            $after = $this->deleteCandidates($api, $policy, $references, $candidates, $before, $prefix, $types, $days);
            $this->line('Exported: ' . json_encode($files, JSON_THROW_ON_ERROR));
            $this->line('Protected before/after: ' . json_encode([$before, $after], JSON_THROW_ON_ERROR));
            $this->info('Completed. Deleted: ' . implode(', ', $this->deleted));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Only our sanitized errors are safe to print; filesystem/JSON errors can contain data.
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Prune failed; diagnostic contents suppressed.');
            $this->line('Already deleted: ' . implode(', ', $this->deleted));
            $this->line('DELETE attempted, removal unverified: ' . implode(', ', $this->unverifiedDeletes));

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function types(): array
    {
        $types = $this->option('type') ?: ['metrics', 'logs'];
        foreach ($types as $type) {
            if (! in_array($type, ['metrics', 'logs'], true)) {
                throw new RuntimeException('Type must be metrics or logs.');
            }
        }

        return array_values(array_unique($types));
    }

    private function days(bool $real): int
    {
        $value = (string) $this->option('min-idle-days');
        if (preg_match('/\A[0-9]{1,5}\z/D', $value) !== 1) {
            throw new RuntimeException('Invalid min-idle-days.');
        }

        $days = (int) $value;
        if ($real && $days < 1) {
            throw new RuntimeException('Forced runs require min-idle-days of at least 1.');
        }

        return $days;
    }

    /** @param list<array<string, mixed>> $streams
     * @return array<string, int>
     */
    private function protectedCounts(array $streams, StreamPolicy $policy): array
    {
        $counts = [];
        foreach ($streams as $stream) {
            if ($policy->protected((string) ($stream['name'] ?? ''))) {
                $count = $stream['stats']['doc_num'] ?? null;
                if (! is_numeric($count) || (int) $count != $count || $count < 0) {
                    throw new RuntimeException('Protected stream count unavailable.');
                }
                $counts[$stream['stream_type'] . ':' . $stream['name']] = (int) $count;
            }
        }
        ksort($counts);

        return $counts;
    }

    /** @param list<array<string, mixed>> $candidates
     * @param array<string, int> $protected
     */
    private function reportInventory(array $candidates, array $protected): void
    {
        foreach ($candidates as $row) {
            $utc = gmdate('Y-m-d\TH:i:s\Z', intdiv((int) $row['max'], 1_000_000));
            $this->line("Candidate {$row['type']}:{$row['name']} docs={$row['count']} newest={$utc} storage_size={$row['size']} MB");
        }
        foreach ($protected as $name => $count) {
            $this->line("Protected {$name} docs={$count} excluded");
        }
    }

    /** @param list<array<string, mixed>> $streams
     * @param list<string> $types
     */
    private function assertManifest(StreamPolicy $policy, array $streams, string $prefix, array $types, int $days, string $hash): void
    {
        $current = $policy->candidates($streams, $prefix, $types, $days);
        $policy->assertIdle($current, $days);
        app(StreamSnapshot::class)->verify($current);
        if (! hash_equals($hash, $policy->hash($current))) {
            throw new RuntimeException('Live candidates changed; deletion aborted.');
        }
    }

    /** @param list<array<string, mixed>> $candidates
     * @param array<string, int> $protected
     * @param list<string> $types
     * @return array<string, int>
     */
    private function deleteCandidates(PruneApi $api, StreamPolicy $policy, StreamReferences $references, array $candidates, array $protected, string $prefix, array $types, int $days): array
    {
        $remaining = $candidates;
        $after = $protected;
        foreach ($candidates as $index => $candidate) {
            $current = $api->streams();
            $this->assertManifest($policy, $current, $prefix, $types, $days, $policy->hash($remaining));
            $references->check($remaining, fn(string $message) => $this->line($message));
            $this->assertProtected($protected, $this->protectedCounts($current, $policy));
            $this->line(($index === 0 ? 'Canary delete: ' : 'Delete: ') . $candidate['type'] . ':' . $candidate['name']);
            $identity = $candidate['type'] . ':' . $candidate['name'];
            $this->unverifiedDeletes[] = $identity;
            $api->request('DELETE', $api->orgPath() . '/streams/' . rawurlencode($candidate['name']) . '?type=' . $candidate['type'] . '&delete_all=false');
            $current = $api->streams();
            foreach ($current as $stream) {
                if ($stream['name'] === $candidate['name'] && $stream['stream_type'] === $candidate['type']) {
                    throw new RuntimeException('Deleted stream still present; stopping immediately.');
                }
            }
            $this->deleted[] = $identity;
            array_pop($this->unverifiedDeletes);
            $after = $this->protectedCounts($current, $policy);
            $this->assertProtected($protected, $after);
            $protected = $after;
            array_shift($remaining);
        }

        return $after;
    }

    /** @param array<string, int> $before
     * @param array<string, int> $after
     */
    private function assertProtected(array $before, array $after): void
    {
        if (array_diff_key($before, $after) !== [] || array_diff_key($after, $before) !== []) {
            throw new RuntimeException('Protected stream inventory changed during deletion.');
        }
        foreach ($before as $name => $count) {
            if (! array_key_exists($name, $after) || $after[$name] < $count) {
                throw new RuntimeException('Protected stream missing or count decreased: ' . $name);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Observability\Pruning\PruneApi;
use App\Services\Observability\Pruning\StreamPolicy;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/** Uses only unique throwaway streams in the local shared OpenObserve. */
final class PruneLegacyStreamsTest extends IntegrationTestCase
{
    public function test_only_legacy_streams_are_exported_and_deleted_and_rerun_is_safe(): void
    {
        $host = parse_url((string) config('openobserve.url'), PHP_URL_HOST);
        self::assertContains($host, ['openobserve', '127.0.0.1', 'localhost'], 'Integration test refuses non-local OpenObserve.');
        $random = bin2hex(random_bytes(8));
        $prefix = 'zzlegacy' . $random . '_';
        $sibling = 'zzcurrent' . $random . '_logs';
        $log = $prefix . 'logs';
        $metric = $prefix . 'metric';
        $directory = base_path('.tmp/prune-integration-' . $random);
        File::ensureDirectoryExists(dirname($directory), 0700);
        $api = app(PruneApi::class);
        $org = $api->orgPath();
        config()->set('openobserve.stream_logs', $sibling);
        $created = [[$log, 'logs'], [$sibling, 'logs'], [$metric, 'metrics']];
        try {
            $this->waitForOpenObserve();
            foreach ([$log, $sibling] as $name) {
                $api->request('POST', $org . '/' . $name . '/_json', [['_timestamp' => (int) (microtime(true) * 1_000_000), 'message' => 'throwaway']]);
            }
            $api->request('POST', $org . '/v1/metrics', ['resourceMetrics' => [['scopeMetrics' => [['metrics' => [[
                'name' => $metric, 'gauge' => ['dataPoints' => [['asDouble' => 1.0, 'timeUnixNano' => (string) (int) (microtime(true) * 1_000_000_000)]]],
            ]]]]]]]);
            $policy = app(StreamPolicy::class);
            $candidates = [];
            $deadline = time() + 90;
            do {
                $inventory = $api->streams();
                $candidates = $policy->candidates($inventory, $prefix, ['logs', 'metrics'], 0);
                $siblings = array_values(array_filter($inventory, static fn(array $row): bool => $row['name'] === $sibling));
                if (count($candidates) === 2 && array_sum(array_column($candidates, 'count')) === 2 && ($siblings[0]['stats']['doc_num'] ?? null) === 1) {
                    break;
                }
                usleep(500_000);
            } while (time() < $deadline);
            self::assertCount(2, $candidates);
            self::assertSame(2, array_sum(array_column($candidates, 'count')));
            self::assertSame(1, $siblings[0]['stats']['doc_num'] ?? null);
            $options = ['--prefix' => $prefix, '--min-idle-days' => '0'];
            $dryRunOutput = new BufferedOutput();
            $dryRunStatus = Artisan::call('observability:prune-legacy-streams', $options, $dryRunOutput);
            $dryRunText = $dryRunOutput->fetch();
            self::assertSame(0, $dryRunStatus, $dryRunText);
            self::assertStringContainsString('Manifest SHA256:', $dryRunText);
            $this->artisan('observability:prune-legacy-streams', [...$options, '--force' => true, '--export-dir' => $directory, '--confirm' => str_repeat('0', 64)])->assertFailed();
            self::assertCount(2, $policy->candidates($api->streams(), $prefix, ['logs', 'metrics'], 0));
            self::assertDirectoryDoesNotExist($directory);
            $this->artisan('observability:prune-legacy-streams', [...$options, '--force' => true, '--export-dir' => $directory, '--confirm' => $policy->hash($candidates)])->assertSuccessful();
            $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertEqualsCanonicalizing([$log, $metric], array_column($manifest['streams'], 'name'));
            foreach ($manifest['streams'] as $file) {
                self::assertSame(1, substr_count(gzdecode(file_get_contents($file['path'])), "\n"));
                self::assertSame(hash_file('sha256', $file['path']), $file['sha256']);
            }
            self::assertSame([], $policy->candidates($api->streams(), $prefix, ['logs', 'metrics'], 0));
            $siblings = array_values(array_filter($api->streams(), static fn(array $row): bool => $row['name'] === $sibling));
            self::assertCount(1, $siblings);
            self::assertSame(1, $siblings[0]['stats']['doc_num']);
            $this->artisan('observability:prune-legacy-streams', $options)->expectsOutputToContain('Nothing to do')->assertFailed();
        } finally {
            File::deleteDirectory($directory);
            // Exact generated identities only; never clean by prefix or touch existing streams.
            foreach ($created as [$name, $type]) {
                $exists = array_filter($api->streams(), static fn(array $row): bool => $row['name'] === $name && $row['stream_type'] === $type);
                if ($exists !== []) {
                    $api->request('DELETE', $org . '/streams/' . $name . '?type=' . $type . '&delete_all=false');
                }
            }
            $remaining = array_filter($api->streams(), static fn(array $row): bool => in_array([$row['name'], $row['stream_type']], $created, true));
            self::assertSame([], array_values($remaining), 'Throwaway streams must be absent after cleanup.');
        }
    }

    private function waitForOpenObserve(): void
    {
        $deadline = time() + 30;
        while (time() < $deadline) {
            try {
                if (Http::baseUrl((string) config('openobserve.url'))
                    ->connectTimeout(2)->timeout(3)->get('/healthz')->successful()) {
                    return;
                }
            } catch (Throwable) {
                // The integration service can still be starting.
            }
            usleep(250_000);
        }

        self::fail('OpenObserve did not become healthy for throwaway-stream integration.');
    }
}

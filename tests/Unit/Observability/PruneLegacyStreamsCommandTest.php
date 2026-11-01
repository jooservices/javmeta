<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Services\Observability\Pruning\StreamPolicy;
use App\Services\Observability\Pruning\StreamReferences;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Observability\Pruning\StreamExport;
use App\Services\Observability\Pruning\StreamSnapshot;
use App\Services\Observability\Pruning\BackupSynchronizer;
use Mockery;
use RuntimeException;
use App\Observability\MetricStreams;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PruneLegacyStreamsCommandTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $streams;

    /** @var array<string, array<mixed>|int> */
    private array $responses = [];

    private string $directory;

    private string $mode = '';

    private int $inventoryReads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        config()->set(['openobserve.url' => 'http://oo.test', 'openobserve.org' => 'default', 'openobserve.stream_logs' => 'customlive_logs', 'openobserve.stream_metrics' => 'customlive_metrics']);
        $this->directory = base_path('.tmp/prune-' . bin2hex(random_bytes(8)));
        File::ensureDirectoryExists(dirname($this->directory), 0700);
        $this->streams = [self::stream('zzlegacy_a'), self::stream('zzlegacy_b', 'metrics'), self::stream('javmeta_live'), self::stream('xcrawler_logs'), self::stream('customlive_logs')];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (isset($this->responses[$path]) && ! str_contains((string) ($request['query']['sql'] ?? ''), 'COUNT(*)')) {
                $value = $this->responses[$path];

                return is_int($value) ? Http::response(['secret' => 'DO-NOT-PRINT'], $value) : Http::response($value);
            }
            if ($request->method() === 'DELETE') {
                $name = basename($path);
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                if ($this->mode === 'canary-stays') {
                    return Http::response(['code' => 200]);
                }
                $this->streams = array_values(array_filter($this->streams, static fn(array $row): bool => $row['name'] !== $name || $row['stream_type'] !== $query['type']));

                return Http::response(['code' => 200]);
            }
            if ($path === '/api/default/streams') {
                $this->inventoryReads++;
                if ($this->mode === 'changed-after-export' && $this->inventoryReads === 2) {
                    $this->streams[0]['stats']['doc_num'] = 2;
                }
                if ($this->mode === 'protected-shrinks-during-export' && $this->inventoryReads === 2) {
                    $this->streams[2]['stats']['doc_num'] = 0;
                }
                if ($this->mode === 'protected-disappears-during-export' && $this->inventoryReads === 2) {
                    array_splice($this->streams, 2, 1);
                }
                if ($this->inventoryReads === 4 && $this->mode === 'protected-shrinks') {
                    $this->streams[2]['stats']['doc_num'] = 0;
                }
                if ($this->inventoryReads === 4 && $this->mode === 'protected-missing') {
                    unset($this->streams[2]);
                    $this->streams = array_values($this->streams);
                }
                if ($this->inventoryReads === 4 && $this->mode === 'protected-added') {
                    $this->streams[] = self::stream('javmeta_new');
                }

                return Http::response(['list' => $this->streams, 'total' => count($this->streams)]);
            }
            if ($path === '/api/default/_search') {
                if (str_contains((string) $request['query']['sql'], 'COUNT(*)')) {
                    return Http::response(['hits' => [['num' => 1, 'oldest' => 1, 'newest' => 1]]]);
                }
                return Http::response(['hits' => [['_timestamp' => 1, 'message' => 'backup']]]);
            }

            return Http::response(str_ends_with($path, 'destinations') ? [] : [str_ends_with($path, '/dashboards') ? 'dashboards' : 'list' => []]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function stream(string $name, string $type = 'logs'): array
    {
        return ['name' => $name, 'stream_type' => $type, 'stats' => ['doc_num' => 1, 'doc_time_min' => 1, 'doc_time_max' => 1, 'storage_size' => 0.1]];
    }

    /** @return array<string, mixed> */
    private function runOptions(): array
    {
        return ['--prefix' => 'zzlegacy_', '--force' => true, '--confirm' => $this->hash(), '--export-dir' => $this->directory];
    }

    private function hash(): string
    {
        $policy = app(StreamPolicy::class);

        return $policy->hash($policy->candidates($this->streams, 'zzlegacy_', ['logs', 'metrics'], 14));
    }

    /** @return list<list<string>> */
    public static function invalidPrefixes(): array
    {
        return array_map(static fn(string $prefix): array => [$prefix], ['javmeta_', 'xcrawler_', 'j', '', 'jvmeta', 'Jvmeta_', 'jvmeta_.*', 'customlive_', 'customlive_logs_', "jvmeta_\n"]);
    }

    #[DataProvider('invalidPrefixes')]
    public function test_invalid_or_protected_prefix_is_refused_before_http(string $prefix): void
    {
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => $prefix])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_exact_legacy_prefix_excludes_live_names_and_substrings(): void
    {
        $this->streams = [self::stream('jvmeta_old'), self::stream('javmeta_live'), self::stream('xcrawler_logs'), self::stream('other_jvmeta_old'), self::stream('jvmeta_'), self::stream('jvmeta_bad-name')];
        $policy = app(StreamPolicy::class);
        self::assertSame(['jvmeta_old'], array_column($policy->candidates($this->streams, 'jvmeta_', ['logs', 'metrics'], 14), 'name'));
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'jvmeta_'])->expectsOutputToContain('Protected logs:javmeta_live docs=1 excluded')->expectsOutputToContain('Protected logs:xcrawler_logs docs=1 excluded')->expectsOutputToContain('Manifest SHA256:')->assertSuccessful();
        Http::assertNotSent(fn(Request $request): bool => in_array($request->method(), ['DELETE', 'PUT', 'PATCH'], true));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function test_manifest_is_sorted_and_changes_when_count_timestamp_or_type_changes(): void
    {
        $policy = app(StreamPolicy::class);
        $rows = $policy->candidates($this->streams, 'zzlegacy_', ['logs', 'metrics'], 14);
        self::assertSame($policy->hash($rows), $policy->hash(array_reverse($rows)));
        foreach (['count' => 2, 'max' => 2, 'type' => 'metrics'] as $key => $value) {
            $changed = $rows;
            $changed[0][$key] = $value;
            self::assertNotSame($policy->hash($rows), $policy->hash($changed));
        }
    }

    public function test_recent_candidate_aborts_without_export_or_delete(): void
    {
        $this->streams[0]['stats']['doc_time_max'] = now()->getTimestamp() * 1_000_000;
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())->expectsOutputToContain('skipped: zzlegacy_a')->assertFailed();
        self::assertDirectoryDoesNotExist($this->directory);
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_zero_idle_days_allows_old_documents(): void
    {
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_', '--min-idle-days' => '0'])->assertSuccessful();
    }

    public function test_forced_run_rejects_zero_idle_days_before_http(): void
    {
        $this->artisan('observability:prune-legacy-streams', [...$this->runOptions(), '--min-idle-days' => '0'])
            ->expectsOutputToContain('min-idle-days of at least 1')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_empty_and_more_than_200_candidates_are_refused(): void
    {
        $this->streams = [];
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_'])->expectsOutputToContain('Nothing to do')->assertFailed();
        $this->streams = array_map(static fn(int $id): array => self::stream('zzlegacy_' . $id), range(0, 200));
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_'])->expectsOutputToContain('More than 200')->assertFailed();
    }

    /** @return list<list<array<string, mixed>>> */
    public static function invalidOptions(): array
    {
        return [[['--force' => true]], [['--type' => ['traces']]], [['--min-idle-days' => '-1']], [['--min-idle-days' => '1.5']], [['--force' => true, '--dry-run' => true, '--confirm' => 'abc', '--export-dir' => 'ignored']]];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_options_refused(array $options): void
    {
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_', ...$options])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_wrong_confirm_does_not_export_or_delete(): void
    {
        $this->artisan('observability:prune-legacy-streams', [...$this->runOptions(), '--confirm' => str_repeat('0', 64)])->expectsOutputToContain('confirmation mismatch')->assertFailed();
        self::assertDirectoryDoesNotExist($this->directory);
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    /** @return list<list<string>> */
    public static function referenceFamilies(): array
    {
        return [['functions'], ['pipelines'], ['destinations'], ['dashboards'], ['alerts']];
    }

    #[DataProvider('referenceFamilies')]
    public function test_reference_in_each_family_blocks_deletion_without_exposing_contents(string $family): void
    {
        $object = ['name' => 'object', 'body' => 'https://secret.invalid/TOP-SECRET', 'stream' => 'zzlegacy_a'];
        $path = '/api/default/' . $family;
        $key = 'list';
        if ($family === 'destinations') {
            $path = '/api/default/alerts/destinations';
            $this->responses[$path] = [$object];
        } elseif ($family === 'dashboards') {
            $this->responses[$path] = ['dashboards' => [['dashboard_id' => 'safe-id', 'folder_id' => 'default']]];
            $this->responses[$path . '/safe-id'] = ['version' => 8, 'v8' => $object];
        } elseif ($family === 'alerts') {
            $path = '/api/v2/default/alerts';
            $this->responses[$path] = ['list' => [['alert_id' => 'safe-id']]];
            $this->responses[$path . '/safe-id'] = ['stream_name' => 'zzlegacy_a', 'query_condition' => $object];
        } else {
            $this->responses[$path] = [$key => [$object]];
        }
        Log::spy();
        Log::shouldReceive('channel')->with('single')->andReturnSelf();
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())->expectsOutputToContain('References candidates: zzlegacy_a')->doesntExpectOutputToContain('TOP-SECRET')->assertFailed();
        Log::shouldNotHaveReceived('info', fn(...$arguments): bool => str_contains(json_encode($arguments), 'TOP-SECRET'));
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_reference_matching_uses_identifier_boundaries(): void
    {
        $references = app(StreamReferences::class);
        self::assertSame([], $references->matches(['sql' => 'select * from zzlegacy_ab'], ['zzlegacy_a']));
        self::assertSame(['zzlegacy_a'], $references->matches(['sql' => 'select * from "zzlegacy_a"'], ['zzlegacy_a']));
        self::assertSame(['zzlegacy_a'], $references->matches(['zzlegacy_a' => ['enabled' => true]], ['zzlegacy_a']));
    }

    public function test_dashboard_check_includes_nondefault_folders_without_pagination(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/default/dashboards') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                // v1.0.4 lists only the default folder when title is absent.
                return Http::response(['dashboards' => array_key_exists('title', $query) && $query['title'] === ''
                    && ! array_key_exists('folder', $query) && ! array_key_exists('pageSize', $query)
                    ? [['dashboard_id' => 'other-folder-dashboard', 'folder_id' => 'other-folder']] : []]);
            }
            if ($path === '/api/default/dashboards/other-folder-dashboard') {
                self::assertSame('other-folder', $request['folder']);

                return Http::response(['version' => 8, 'v8' => ['sql' => 'SELECT * FROM zzlegacy_a']]);
            }

            return Http::response(str_ends_with($path, 'destinations') ? [] : ['list' => []]);
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('References candidates: zzlegacy_a');
        app(StreamReferences::class)->check([['name' => 'zzlegacy_a']], static function (string $message): void {});
    }

    public function test_unavailable_or_malformed_reference_inventory_fails_closed(): void
    {
        foreach ([404, ['unexpected' => []], ['list' => [], 'total' => 1]] as $value) {
            $this->responses['/api/default/functions'] = $value;
            $this->artisan('observability:prune-legacy-streams', $this->runOptions())->expectsOutputToContain('Could not clear functions')->doesntExpectOutputToContain('DO-NOT-PRINT')->assertFailed();
        }
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_export_recounts_and_checksums_files_before_deleting_only_candidates(): void
    {
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())->expectsOutputToContain('Canary delete: logs:zzlegacy_a')->assertSuccessful();
        $manifest = json_decode(file_get_contents($this->directory . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $manifest['streams']);
        foreach ($manifest['streams'] as $file) {
            self::assertSame(hash_file('sha256', $file['path']), $file['sha256']);
            self::assertSame(filesize($file['path']), $file['bytes']);
            self::assertSame(1, substr_count(gzdecode(file_get_contents($file['path'])), "\n"));
        }
        self::assertSame(['javmeta_live', 'xcrawler_logs', 'customlive_logs'], array_column($this->streams, 'name'));
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_'])->expectsOutputToContain('Nothing to do')->assertFailed();
    }

    public function test_export_count_mismatch_partial_search_and_bad_timestamps_abort_before_delete(): void
    {
        foreach ([['hits' => []], ['hits' => [['_timestamp' => 1]], 'is_partial' => true], ['hits' => [['_timestamp' => 0]]]] as $body) {
            $this->responses['/api/default/_search'] = $body;
            $this->artisan('observability:prune-legacy-streams', $this->runOptions())->assertFailed();
            File::deleteDirectory($this->directory);
        }
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_existing_backup_directory_is_never_overwritten(): void
    {
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory . '/keep', 'keep');
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())->assertFailed();
        self::assertSame('keep', file_get_contents($this->directory . '/keep'));
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_failed_delete_has_no_retry_and_reports_already_deleted(): void
    {
        $this->responses['/api/default/streams/zzlegacy_a'] = 500;
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())
            ->expectsOutputToContain('HTTP 500')
            ->expectsOutputToContain('DELETE attempted, removal unverified: logs:zzlegacy_a')->assertFailed();
        self::assertCount(1, Http::recorded(fn(Request $request): bool => $request->method() === 'DELETE'));
        self::assertCount(5, $this->streams);
    }
    /** @return list<list<string>> */
    public static function deletionDiscrepancies(): array
    {
        return [['protected-shrinks'], ['protected-missing'], ['protected-added'], ['canary-stays']];
    }

    #[DataProvider('deletionDiscrepancies')]
    public function test_first_discrepancy_stops_before_second_delete(string $mode): void
    {
        $this->mode = $mode;
        if ($mode === 'canary-stays') {
            $this->artisan('observability:prune-legacy-streams', $this->runOptions())
                ->expectsOutputToContain('DELETE attempted, removal unverified: logs:zzlegacy_a')
                ->doesntExpectOutputToContain('Already deleted: logs:zzlegacy_a')->assertFailed();
        } else {
            $this->artisan('observability:prune-legacy-streams', $this->runOptions())
                ->expectsOutputToContain('Already deleted: logs:zzlegacy_a')->assertFailed();
        }
        self::assertCount(1, Http::recorded(fn(Request $request): bool => $request->method() === 'DELETE'));
        self::assertContains('zzlegacy_b', array_column($this->streams, 'name'));
    }

    public function test_changed_state_after_export_aborts_before_first_delete(): void
    {
        $this->mode = 'changed-after-export';
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())->assertFailed();
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
        self::assertFileExists($this->directory . '/manifest.json');
    }

    public function test_protected_state_cannot_shrink_or_disappear_during_export(): void
    {
        $original = $this->streams;
        foreach (['protected-shrinks-during-export', 'protected-disappears-during-export'] as $mode) {
            $this->streams = $original;
            $this->inventoryReads = 0;
            $this->mode = $mode;
            $this->artisan('observability:prune-legacy-streams', $this->runOptions())->assertFailed();
            File::deleteDirectory($this->directory);
        }
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_audit_failure_after_delete_request_reports_unverified_not_confirmed(): void
    {
        Log::shouldReceive('channel')->with('single')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(static function (string $message, array $context): void {
            if ($context['method'] === 'DELETE') {
                throw new RuntimeException('TOP-SECRET logging failure');
            }
        });
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())
            ->expectsOutputToContain('Already deleted: ')
            ->expectsOutputToContain('DELETE attempted, removal unverified: logs:zzlegacy_a')
            ->doesntExpectOutputToContain('Already deleted: logs:zzlegacy_a')
            ->doesntExpectOutputToContain('TOP-SECRET')->assertFailed();
        self::assertCount(1, Http::recorded(fn(Request $request): bool => $request->method() === 'DELETE'));
    }

    public function test_sync_failure_on_any_backup_component_aborts_before_deletion(): void
    {
        foreach (['logs-zzlegacy_a.ndjson.gz', 'metrics-zzlegacy_b.ndjson.gz', 'manifest.json', '', '..'] as $failure) {
            $sync = Mockery::mock(BackupSynchronizer::class);
            $sync->shouldReceive('sync')->andReturnUsing(function (string $path) use ($failure): void {
                $expected = $failure === '..' ? dirname($this->directory) : $this->directory . ($failure === '' ? '' : '/' . $failure);
                if ($path === $expected) {
                    throw new RuntimeException('Cannot sync backup storage.');
                }
            });
            $this->app->instance(BackupSynchronizer::class, $sync);
            $this->artisan('observability:prune-legacy-streams', $this->runOptions())
                ->expectsOutputToContain('Cannot sync backup storage')->assertFailed();
            File::deleteDirectory($this->directory);
        }
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_searchable_buffer_must_agree_with_metadata_even_when_metadata_is_empty(): void
    {
        $this->streams[0]['stats']['doc_num'] = 0;
        $this->streams[0]['stats']['doc_time_min'] = 0;
        $this->streams[0]['stats']['doc_time_max'] = 0;
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())
            ->expectsOutputToContain('Searchable state differs')->assertFailed();
        self::assertDirectoryDoesNotExist($this->directory);
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_repeatable_type_limits_candidates_and_derived_emitter_namespace_is_protected(): void
    {
        $policy = app(StreamPolicy::class);
        self::assertTrue($policy->protected(MetricStreams::PREFIX . 'future_metric'));
        foreach (MetricStreams::NAMES as $name) {
            self::assertTrue($policy->protected($name), "Emitter metric {$name} must be protected.");
        }
        self::assertTrue($policy->protected('customlive_metrics'));
        self::assertSame(['zzlegacy_b'], array_column($policy->candidates($this->streams, 'zzlegacy_', ['metrics'], 14), 'name'));
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_', '--type' => ['metrics']])
            ->expectsOutputToContain('Candidate metrics:zzlegacy_b')->doesntExpectOutputToContain('Candidate logs:zzlegacy_a')->assertSuccessful();
    }

    public function test_missing_statistics_and_incomplete_inventory_are_refused(): void
    {
        unset($this->streams[0]['stats']['doc_time_max']);
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_'])->assertFailed();
        $this->responses['/api/default/streams'] = ['list' => [], 'total' => 1];
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_'])->assertFailed();
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_dense_time_slices_export_every_document_without_truncation(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $query = $request['query'];
            $hits = [];
            $start = (int) $query['start_time'];
            $end = (int) $query['end_time'];
            for ($stamp = max(1, $start); $stamp < min(1002, $end) && count($hits) < 1000; $stamp++) {
                $hits[] = ['_timestamp' => $stamp];
            }

            return Http::response(['hits' => $hits, 'total' => count($hits)]);
        });
        $files = app(StreamExport::class)->export([
            ['name' => 'zzlegacy_dense', 'type' => 'logs', 'count' => 1001, 'min' => 1, 'max' => 1001, 'size' => 1.0],
        ], $this->directory, 'hash');
        self::assertSame(1001, substr_count(gzdecode(file_get_contents($files[0]['path'])), "\n"));
        self::assertGreaterThan(1, Http::recorded()->count());
    }

    public function test_transport_errors_hide_exception_contents_and_log_only_safe_call_metadata(): void
    {
        Http::fake(fn() => throw new ConnectionException('TOP-SECRET'));
        Log::spy();
        Log::shouldReceive('channel')->with('single')->andReturnSelf();
        $this->artisan('observability:prune-legacy-streams', ['--prefix' => 'zzlegacy_'])
            ->expectsOutputToContain('transport failed')->doesntExpectOutputToContain('TOP-SECRET')->assertFailed();
        Log::shouldHaveReceived('info')->with('OpenObserve prune API', ['method' => 'GET', 'path' => '/api/default/streams', 'status' => 0])->once();
    }
    public function test_exactly_200_candidates_are_allowed(): void
    {
        $rows = array_map(static fn(int $id): array => self::stream('zzlegacy_' . $id), range(1, 200));
        self::assertCount(200, app(StreamPolicy::class)->candidates($rows, 'zzlegacy_', ['logs'], 14));
    }

    public function test_a_thousand_documents_at_one_microsecond_are_refused_without_truncation(): void
    {
        Http::swap(new Factory());
        Http::fake(fn() => Http::response(['hits' => array_fill(0, 1000, ['_timestamp' => 1]), 'total' => 1000]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many documents at one timestamp');
        app(StreamExport::class)->export([
            ['name' => 'zzlegacy_dense', 'type' => 'logs', 'count' => 1000, 'min' => 1, 'max' => 1, 'size' => 1.0],
        ], $this->directory, 'hash');
    }

    public function test_search_snapshot_rejects_partial_results(): void
    {
        Http::swap(new Factory());
        Http::fake(fn() => Http::response(['hits' => [['num' => 1, 'oldest' => 1, 'newest' => 1]], 'is_partial' => true]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot verify searchable');
        app(StreamSnapshot::class)->verify([['name' => 'zzlegacy_a', 'type' => 'logs', 'count' => 1, 'min' => 1, 'max' => 1]]);
    }

    public function test_verified_empty_stream_produces_an_empty_backup_without_invalid_zero_time_search(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake(fn() => Http::response(['error' => 'start_time must be positive'], 400));
        $files = app(StreamExport::class)->export([
            ['name' => 'zzlegacy_empty', 'type' => 'logs', 'count' => 0, 'min' => 0, 'max' => 0, 'size' => 0.0],
        ], $this->directory, 'hash');
        self::assertSame('', gzdecode(file_get_contents($files[0]['path'])));
        self::assertSame(0, $files[0]['count']);
        Http::assertNothingSent();
    }

    public function test_missing_dashboard_and_alert_details_fail_closed_and_report_all_families(): void
    {
        $this->responses['/api/default/dashboards'] = ['dashboards' => [['dashboard_id' => 'missing']]];
        $this->responses['/api/default/dashboards/missing'] = [];
        $this->responses['/api/v2/default/alerts'] = ['list' => [['alert_id' => 'missing']]];
        $this->responses['/api/v2/default/alerts/missing'] = [];
        $this->artisan('observability:prune-legacy-streams', $this->runOptions())
            ->expectsOutputToContain('Could not clear dashboards')->expectsOutputToContain('Could not clear alerts')
            ->expectsOutputToContain('Checked destinations:')->assertFailed();
        Http::assertNotSent(fn(Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_snapshot_uses_positive_search_start_and_bounded_timeouts(): void
    {
        Http::swap(new Factory());
        Http::fake(function (Request $request, array $options) {
            self::assertSame(1, $request['query']['start_time']);
            self::assertSame(30, $options['timeout']);
            self::assertSame(5, $options['connect_timeout']);
            self::assertFalse($options['allow_redirects']);

            return Http::response(['hits' => [['num' => 1, 'oldest' => 1, 'newest' => 1]]]);
        });
        app(StreamSnapshot::class)->verify([['name' => 'zzlegacy_a', 'type' => 'logs', 'count' => 1, 'min' => 1, 'max' => 1]]);
        Http::assertSentCount(1);
    }
}

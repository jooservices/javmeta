<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Services\Observability\Pruning\BackupSynchronizer;
use App\Services\Observability\Pruning\PruneApi;
use App\Services\Observability\Pruning\StreamExport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(StreamExport::class)]
final class StreamExportTest extends TestCase
{
    private string $base;

    /** @var list<string> */
    private array $synced = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['openobserve.url' => 'http://oo.test:5080', 'openobserve.org' => 'default', 'openobserve.email' => 'a@b.test', 'openobserve.password' => 'x']);
        $this->base = sys_get_temp_dir() . '/javmeta-export-' . bin2hex(random_bytes(5));
        mkdir($this->base, 0700);
        $this->synced = [];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_a_stream_is_exported_verified_and_described_in_a_manifest(): void
    {
        Http::fake(['oo.test:5080/*' => Http::response(['hits' => [['_timestamp' => 100, 'm' => 'a'], ['_timestamp' => 101, 'm' => 'b']], 'total' => 2])]);
        $candidate = ['type' => 'logs', 'name' => 'app', 'count' => 2, 'min' => 100, 'max' => 101];

        $files = $this->export([$candidate], 'hash-1');

        self::assertCount(1, $files);
        self::assertSame($this->base . '/out/logs-app.ndjson.gz', $files[0]['path']);
        self::assertSame(hash_file('sha256', $files[0]['path']), $files[0]['sha256']);
        self::assertSame(filesize($files[0]['path']), $files[0]['bytes']);
        self::assertSame(2, substr_count((string) gzdecode((string) file_get_contents($files[0]['path'])), "\n"));
        $manifest = json_decode((string) file_get_contents($this->base . '/out/manifest.json'), true);
        self::assertSame(['org' => 'default', 'hash' => 'hash-1', 'streams' => $files], $manifest + ['streams' => $manifest['streams']]);
        self::assertSame(0600, fileperms($this->base . '/out/manifest.json') & 0777);
        self::assertSame(0700, fileperms($this->base . '/out') & 0777);
        self::assertContains($this->base . '/out/manifest.json', $this->synced);
        self::assertContains($this->base . '/out', $this->synced);
        self::assertContains($this->base, $this->synced);
    }

    public function test_an_empty_stream_exports_an_empty_file_without_calling_the_search_api(): void
    {
        Http::fake();

        $files = $this->export([['type' => 'logs', 'name' => 'empty', 'count' => 0, 'min' => 0, 'max' => 0]], 'h');

        self::assertSame(0, substr_count((string) gzdecode((string) file_get_contents($files[0]['path'])), "\n"));
        Http::assertNothingSent();
    }

    public function test_a_dense_window_is_split_until_every_slice_is_complete(): void
    {
        Http::fake(function (Request $request) {
            $query = $request['query'];
            $span = $query['end_time'] - $query['start_time'];
            if ($span > 2) {
                return Http::response(['hits' => array_fill(0, 1000, ['_timestamp' => $query['start_time']]), 'total' => 1000]);
            }

            return Http::response(['hits' => [['_timestamp' => $query['start_time']]], 'total' => 1]);
        });

        $files = $this->export([['type' => 'logs', 'name' => 'dense', 'count' => 2, 'min' => 100, 'max' => 103]], 'h');

        self::assertSame(2, $files[0]['count']);
    }

    public function test_a_window_longer_than_a_day_is_split_before_it_is_searched(): void
    {
        $day = 86_400_000_000;
        $searched = [];
        Http::fake(function (Request $request) use (&$searched) {
            $searched[] = $request['query']['end_time'] - $request['query']['start_time'];

            return Http::response(['hits' => [['_timestamp' => $request['query']['start_time']]], 'total' => 1]);
        });

        $this->export([['type' => 'logs', 'name' => 'long', 'count' => 3, 'min' => 0, 'max' => 2 * $day]], 'h');

        self::assertCount(3, $searched);
        foreach ($searched as $span) {
            self::assertLessThanOrEqual($day, $span);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedAnswers(): iterable
    {
        yield 'partial result' => [['hits' => [], 'total' => 0, 'is_partial' => true], 'Partial or failed search export refused.'];
        yield 'function error' => [['hits' => [], 'function_error' => 'x'], 'Partial or failed search export refused.'];
        yield 'search error' => [['hits' => [], 'error' => 'x'], 'Partial or failed search export refused.'];
        yield 'total smaller than hits' => [['hits' => [['_timestamp' => 100]], 'total' => 0], 'Search export returned inconsistent result totals.'];
        yield 'document outside the slice' => [['hits' => [['_timestamp' => 5000]]], 'Search returned an out-of-slice document.'];
        yield 'document without a timestamp' => [['hits' => [['m' => 'x']]], 'Search returned an out-of-slice document.'];
        yield 'count mismatch' => [['hits' => [['_timestamp' => 100]]], 'Export document count mismatch; no deletion allowed.'];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAnswers')]
    public function test_a_suspicious_search_answer_stops_the_export_before_anything_can_be_deleted(array $answer, string $message): void
    {
        Http::fake(['oo.test:5080/*' => Http::response($answer)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->export([['type' => 'logs', 'name' => 'app', 'count' => 2, 'min' => 100, 'max' => 101]], 'h');
    }

    public function test_too_many_documents_at_one_timestamp_cannot_prove_completeness(): void
    {
        Http::fake(['oo.test:5080/*' => Http::response(['hits' => array_fill(0, 1000, ['_timestamp' => 100]), 'total' => 1000])]);

        $this->expectExceptionMessage('Too many documents at one timestamp; export cannot prove completeness.');

        $this->export([['type' => 'logs', 'name' => 'app', 'count' => 1000, 'min' => 100, 'max' => 100]], 'h');
    }

    public function test_an_existing_or_empty_target_directory_is_refused(): void
    {
        Http::fake();
        mkdir($this->base . '/taken', 0700);

        foreach (['', $this->base . '/taken'] as $directory) {
            try {
                (new StreamExport(new PruneApi(), $this->synchronizer()))->export([], $directory, 'h');
                self::fail('The directory must be new.');
            } catch (RuntimeException $error) {
                self::assertSame('Export requires a new, writable directory.', $error->getMessage());
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function export(array $candidates, string $hash): array
    {
        return (new StreamExport(new PruneApi(), $this->synchronizer()))->export($candidates, $this->base . '/out', $hash);
    }

    private function synchronizer(): BackupSynchronizer
    {
        return new class ($this->synced) implements BackupSynchronizer {
            /** @param list<string> $synced */
            public function __construct(private array &$synced) {}

            public function sync(string $path): void
            {
                $this->synced[] = $path;
            }
        };
    }
}

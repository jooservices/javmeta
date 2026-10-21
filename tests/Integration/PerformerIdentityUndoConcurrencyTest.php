<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerIdentityService;
use Illuminate\Support\Facades\DB;

/**
 * The Feature suite runs on sqlite, which has no row locks. This overlaps a real backfill merge (mergeRoots) with
 * performers:undo-split on Postgres, holds both behind the same row locks, and releases them only once Postgres
 * reports both backends waiting on a lock, so a lock-order deadlock would surface as a deadlock error.
 */
final class PerformerIdentityUndoConcurrencyTest extends IntegrationTestCase
{
    public function test_undo_and_a_concurrent_merge_lock_in_the_same_order_without_deadlock(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        $first = $this->sourcePerformer('source-a', 'person-a', 'Aoi Sakura');
        $second = $this->sourcePerformer('source-b', 'person-b', 'Sakura Aoi');
        $identity = app(PerformerIdentityService::class);
        $identity->backfill();
        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->firstOrFail();
        $split = $identity->split((int) $merge->id);

        $other = DB::connectUsing('identity_concurrent', (array) config('database.connections.pgsql'));
        $other->beginTransaction();
        $processes = [];

        try {
            $other->table('performers')->whereIn('id', [$first->id, $second->id])->orderBy('id')->lockForUpdate()->get(['id']);

            $commands = [
                'undo' => ['performers:undo-split', (string) $split->id],
                'merge' => ['performers:backfill-identities'],
            ];
            foreach ($commands as $name => $arguments) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, 'artisan', ...$arguments],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    base_path(),
                    [...getenv(), 'JAVMETA_SEARCH_ENABLED' => 'false'],
                );
                self::assertIsResource($process);
                $processes[$name] = [$process, $pipes];
            }

            $this->waitForLockWaiters(2);
            foreach ($processes as [$process]) {
                self::assertTrue(proc_get_status($process)['running'], 'Both processes must be blocked on the group row locks.');
            }

            $other->commit();
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
        }

        $outputs = [];
        $exitCodes = [];
        foreach ($processes as $name => [$process, $pipes]) {
            $outputs[$name] = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCodes[$name] = $this->exitCode($process);
        }

        self::assertStringNotContainsStringIgnoringCase('deadlock', implode("\n", $outputs));
        self::assertSame(0, $exitCodes['merge'], 'The backfill must finish: ' . $outputs['merge']);
        self::assertContains($exitCodes['undo'], [0, 1], 'Undo must succeed or be refused cleanly: ' . $outputs['undo']);
        if ($exitCodes['undo'] === 1) {
            // The merge won the race, so the split can no longer be undone and the guard says so.
            self::assertMatchesRegularExpression('/Restore later identity changes|Split later merge events first/', $outputs['undo']);
        }

        $pairIds = [(int) $first->id, (int) $second->id];
        $roots = Performer::query()->whereIn('id', $pairIds)->whereNull('canonical_performer_id')->pluck('id')->map(fn(mixed $id): int => (int) $id)->all();
        self::assertCount(1, $roots, 'Both performers must end up merged into exactly one canonical record.');
        $root = $roots[0];
        $child = $root === $pairIds[0] ? $pairIds[1] : $pairIds[0];
        self::assertSame($root, (int) Performer::query()->findOrFail($child)->canonical_performer_id);
        self::assertSame($root, $identity->canonicalId($child));
        self::assertSame(
            [$root, $root],
            PerformerSource::query()->whereIn('source_performer_id', $pairIds)->orderBy('id')->pluck('performer_id')->map(fn(mixed $id): int => (int) $id)->all(),
        );

        self::assertSame(
            1,
            PerformerIdentityEvent::query()->where('event_type', 'merge')->whereNull('reversed_at')->count(),
            'Exactly one merge event must be active.',
        );
        self::assertSame(1, PerformerIdentityEvent::query()->where('event_type', 'split')->count());
        $undoEvents = PerformerIdentityEvent::query()->where('event_type', 'undo')->get();
        if ($exitCodes['undo'] === 0) {
            self::assertCount(1, $undoEvents);
            self::assertSame((int) $split->id, (int) $undoEvents->first()->related_event_id);
            self::assertNotNull($split->fresh()->reversed_at);
        } else {
            self::assertCount(0, $undoEvents);
            self::assertNull($split->fresh()->reversed_at);
        }
    }

    /** @param resource $process */
    private function exitCode($process): int
    {
        $deadline = microtime(true) + 30;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                proc_close($process);

                return (int) $status['exitcode'];
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        proc_close($process);
        self::fail('A command did not finish after the locks were released.');
    }

    private function waitForLockWaiters(int $expected): void
    {
        $deadline = microtime(true) + 30;
        do {
            $waiting = (int) DB::selectOne(
                "select count(*) as total from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid() and wait_event_type = 'Lock'",
            )->total;
            if ($waiting >= $expected) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('Postgres never reported ' . $expected . ' backends waiting on a lock.');
    }

    private function sourcePerformer(string $source, string $externalId, string $name): Performer
    {
        $performer = Performer::factory()->create([
            'source_slug' => $source,
            'external_id' => $externalId,
            'name_romaji' => $name,
            'birth_date' => '1990-01-01',
        ]);
        PerformerSource::query()->create([
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'site_slug' => $source,
            'mongo_id' => $source . ':' . $externalId,
            'external_id' => $externalId,
            'source_url' => 'https://' . $source . '.test/' . $externalId,
            'name_romaji' => $name,
            'birth_date' => '1990-01-01',
            'crawled_at' => now(),
        ]);

        return $performer;
    }
}

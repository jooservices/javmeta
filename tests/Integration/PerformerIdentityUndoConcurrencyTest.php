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

        $outputs = '';
        foreach ($processes as [$process, $pipes]) {
            $outputs .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        self::assertStringNotContainsStringIgnoringCase('deadlock', $outputs);
        $canonical = $identity->canonicalId((int) $second->id);
        self::assertContains($canonical, [(int) $first->id, (int) $second->id]);
        self::assertSame($canonical, $identity->canonicalId($canonical));
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

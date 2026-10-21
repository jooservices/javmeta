<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerIdentityService;
use Illuminate\Support\Facades\DB;

/**
 * The Feature suite runs on sqlite, which has no row locks. This overlaps a source link written by an in-flight
 * transaction with performers:undo-split on real Postgres and proves undoSplit() waits for the group lock.
 */
final class PerformerIdentityUndoConcurrencyTest extends IntegrationTestCase
{
    public function test_undo_waits_for_a_concurrent_source_link_and_moves_it_to_the_canonical_performer(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        $first = $this->sourcePerformer('source-a', 'person-a', 'Aoi Sakura');
        $second = $this->sourcePerformer('source-b', 'person-b', 'Sakura Aoi');
        $identity = app(PerformerIdentityService::class);
        $identity->backfill();
        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->firstOrFail();
        $split = $identity->split((int) $merge->id);

        // A second connection stands in for a concurrent merge or ingest that holds the group rows and adds a link.
        $other = DB::connectUsing('identity_concurrent', (array) config('database.connections.pgsql'));
        $other->beginTransaction();
        $process = null;
        $pipes = [];

        try {
            $other->table('performers')->whereIn('id', [$first->id, $second->id])->orderBy('id')->lockForUpdate()->get(['id']);
            $other->table('performer_sources')->insert([
                'performer_id' => $second->id,
                'source_performer_id' => $second->id,
                'site_slug' => 'source-b',
                'mongo_id' => 'source-b:person-b-late',
                'external_id' => 'person-b-late',
                'source_url' => 'https://source-b.test/person-b-late',
                'name_romaji' => 'Sakura Aoi',
                'birth_date' => '1990-01-01',
                'crawled_at' => now()->toDateTimeString(),
            ]);

            $process = proc_open(
                [PHP_BINARY, 'artisan', 'performers:undo-split', (string) $split->id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
                [...getenv(), 'JAVMETA_SEARCH_ENABLED' => 'false'],
            );
            self::assertIsResource($process);

            usleep(1_500_000);
            self::assertTrue(proc_get_status($process)['running'], 'undoSplit() must wait for the group row locks.');
            self::assertNull(PerformerIdentityEvent::query()->find($split->id)?->reversed_at);

            $other->commit();
        } finally {
            if ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
        }

        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);

        $late = PerformerSource::query()->where('external_id', 'person-b-late')->firstOrFail();
        self::assertSame((int) $first->id, (int) $late->performer_id);
        self::assertSame((int) $first->id, (int) $second->fresh()->canonical_performer_id);
        self::assertSame((int) $first->id, $identity->canonicalId((int) $second->id));
        self::assertCount(3, $first->fresh()->sources);
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

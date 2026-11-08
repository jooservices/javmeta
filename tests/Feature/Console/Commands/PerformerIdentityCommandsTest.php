<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Gap coverage for performers:source-removed, performers:split and performers:undo-split. */
final class PerformerIdentityCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('elasticsearch.enabled', false);
    }

    public function test_source_removed_marks_the_source_record_and_keeps_the_canonical_performer(): void
    {
        $first = $this->sourcePerformer('source-a', 'person-a', 'Aoi Sakura');
        $this->sourcePerformer('source-b', 'person-b', 'Sakura Aoi');
        app(PerformerIdentityService::class)->backfill();

        $this->artisan('performers:source-removed', ['source_slug' => 'source-b', 'external_id' => 'person-b'])
            ->expectsOutputToContain('Source record marked removed. The canonical performer was retained.')
            ->assertSuccessful();

        self::assertNotNull(PerformerSource::query()->where('external_id', 'person-b')->firstOrFail()->removed_at);
        self::assertNull(PerformerSource::query()->where('external_id', 'person-a')->firstOrFail()->removed_at);
        self::assertNotNull(Performer::query()->find($first->id));
    }

    public function test_source_removed_fails_for_an_unknown_source_record(): void
    {
        $this->artisan('performers:source-removed', ['source_slug' => 'missing', 'external_id' => 'nobody'])
            ->expectsOutputToContain('The source performer record was not found.')
            ->assertFailed();
    }

    public function test_split_and_undo_split_reverse_and_restore_a_merge(): void
    {
        $first = $this->sourcePerformer('source-a', 'person-a', 'Aoi Sakura');
        $second = $this->sourcePerformer('source-b', 'person-b', 'Sakura Aoi');
        app(PerformerIdentityService::class)->backfill();
        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->firstOrFail();
        self::assertSame((int) $first->id, (int) $second->fresh()->canonical_performer_id);

        $this->artisan('performers:split', ['merge_event_id' => $merge->id])
            ->expectsOutputToContain('Split recorded as event')
            ->assertSuccessful();

        $split = PerformerIdentityEvent::query()->where('event_type', 'split')->firstOrFail();
        self::assertNull($second->fresh()->canonical_performer_id);

        $this->artisan('performers:undo-split', ['split_event_id' => $split->id])
            ->expectsOutputToContain('Merge restored as event')
            ->assertSuccessful();

        self::assertSame((int) $first->id, (int) $second->fresh()->canonical_performer_id);
    }

    public function test_split_fails_when_the_merge_event_is_not_active(): void
    {
        $this->artisan('performers:split', ['merge_event_id' => 999])
            ->expectsOutputToContain('An active merge event was not found.')
            ->assertFailed();
    }

    public function test_undo_split_fails_when_the_split_event_is_not_active(): void
    {
        $this->artisan('performers:undo-split', ['split_event_id' => 999])
            ->expectsOutputToContain('An active split event was not found.')
            ->assertFailed();
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

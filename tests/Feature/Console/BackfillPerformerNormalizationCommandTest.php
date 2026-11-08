<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\IndexPerformerJob;
use App\Models\Performer;
use App\Models\PerformerSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class BackfillPerformerNormalizationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_changes_without_writing_and_apply_resumes_and_reindexes(): void
    {
        Queue::fake();
        config(['elasticsearch.enabled' => true]);
        config(['javmeta_normalization.backfill_chunk_size' => 1]);
        $this->artisan('list')->expectsOutputToContain('performers:backfill-normalization')->assertSuccessful();
        $performer = Performer::factory()->create([
            'source_slug' => 'xcity',
            'birth_date' => '1899-12-31',
            'height_cm' => 99,
            'bust' => 251,
            'waist' => 29,
            'hip' => 251,
            'cup' => 'FANZA',
            'blood_type' => 'Ａ型 Rh＋',
            'blood_rh' => null,
            'debut_date' => '1939-12-31',
            'location' => 'Tokyo',
        ]);
        $source = PerformerSource::query()->create([
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'site_slug' => 'avjoho',
            'mongo_id' => 'local:' . $performer->id,
            'external_id' => (string) $performer->id,
            'birth_date' => '1899-12-31',
            'crawled_at' => now(),
        ]);
        $validSource = PerformerSource::query()->create([
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'site_slug' => 'javdb',
            'mongo_id' => 'valid:' . $performer->id,
            'external_id' => 'valid-' . $performer->id,
            'birth_date' => '1990-02-03',
            'crawled_at' => now(),
        ]);
        $sourceOnlyPerformer = Performer::factory()->create(['source_slug' => 'jaedb']);
        $sourceOnlyBirthDate = PerformerSource::query()->create([
            'performer_id' => $sourceOnlyPerformer->id,
            'source_performer_id' => $sourceOnlyPerformer->id,
            'site_slug' => 'jaedb',
            'mongo_id' => 'local:' . $sourceOnlyPerformer->id,
            'external_id' => (string) $sourceOnlyPerformer->id,
            'birth_date' => '1899-12-31',
            'crawled_at' => now(),
        ]);

        $this->artisan('performers:backfill-normalization')
            ->assertSuccessful()
            ->expectsOutputToContain('dry-run')
            ->expectsOutputToContain('Will change')
            ->expectsOutputToContain('performer_sources.birth_date')
            ->expectsOutputToContain('Scanned 2 performer rows');
        $this->assertDatabaseHas('performers', [
            'id' => $performer->id,
            'blood_type' => 'Ａ型 Rh＋',
            'cup' => 'FANZA',
            'location' => 'Tokyo',
        ]);
        self::assertSame('1899-12-31', $source->fresh()->birth_date?->format('Y-m-d'));
        self::assertSame('1899-12-31', $sourceOnlyBirthDate->fresh()->birth_date?->format('Y-m-d'));
        self::assertSame('1990-02-03', $validSource->fresh()->birth_date?->format('Y-m-d'));
        $this->assertDatabaseCount('normalization_rejections', 0);
        Queue::assertNothingPushed();

        $this->artisan('performers:backfill-normalization', ['--apply' => true, '--chunk' => 1])
            ->assertSuccessful()
            ->expectsOutputToContain('Mode: apply')
            ->expectsOutputToContain('--after=' . $performer->id);
        $this->assertDatabaseHas('performers', [
            'id' => $performer->id,
            'birth_date' => null,
            'height_cm' => null,
            'bust' => null,
            'waist' => null,
            'hip' => null,
            'cup' => null,
            'blood_type' => 'A',
            'blood_rh' => '+',
            'debut_date' => null,
            'location' => '東京都',
        ]);
        $this->assertDatabaseHas('performer_sources', ['id' => $source->id, 'birth_date' => null]);
        $this->assertDatabaseHas('performer_sources', ['id' => $sourceOnlyBirthDate->id, 'birth_date' => null]);
        self::assertSame('1990-02-03', $validSource->fresh()->birth_date?->format('Y-m-d'));
        Queue::assertPushed(IndexPerformerJob::class, 2);
        $this->assertDatabaseHas('normalization_rejections', [
            'entity' => 'performer',
            'field' => 'cup',
            'source' => 'xcity',
            'rejected_total' => 1,
        ]);
        $this->assertDatabaseHas('normalization_rejections', [
            'entity' => 'performer',
            'field' => 'birth_date',
            'source' => 'avjoho',
            'rejected_total' => 1,
        ]);

        $this->artisan('performers:backfill-normalization', ['--apply' => true, '--after' => $sourceOnlyPerformer->id])
            ->assertSuccessful()
            ->expectsOutputToContain('Scanned 0 performer rows');
        Queue::assertPushed(IndexPerformerJob::class, 2);
    }
}

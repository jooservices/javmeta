<?php

declare(strict_types=1);

namespace Tests\Feature\Persist;

use App\Data\Crawl\PerformerDraft;
use App\Data\Crawl\MovieDraft;
use App\Jobs\IndexPerformerJob;
use App\Models\Performer;
use App\Models\PerformerSource;
use App\Services\Normalize\PerformerAttributeNormalizer;
use App\Services\Persist\MoviePersister;
use App\Services\Persist\PerformerPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

final class PerformerNormalizationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_profile_values_are_canonical_before_postgres_mongo_and_search_queue(): void
    {
        $draft = new PerformerDraft(
            sourceSlug: 'xcity',
            externalId: '1',
            nameRomaji: 'Test Performer',
            profileUrl: 'https://xcity.test/performer/1',
            birthDate: '1899-12-31',
            heightCm: 99,
            bust: 251,
            waist: 29,
            hip: 251,
            cup: 'FANZA',
            bloodType: 'Ａ型 Rh＋',
            debutDate: '1939-12-31',
            location: '–',
        );
        $persister = app(PerformerPersister::class);
        $normalizedDraft = app(PerformerAttributeNormalizer::class)->normalizeDraft($draft, false);
        $method = new ReflectionMethod(PerformerPersister::class, 'archivePayload');
        $payload = $method->invoke($persister, $normalizedDraft, [
            'blood_type' => 'Ａ型 Rh＋',
            'cup' => 'FANZA',
            'location' => '–',
            'metadata' => ['blood_type' => 'Trans', 'cup' => 'Q'],
        ]);
        self::assertSame([
            'birth_date' => null,
            'height_cm' => null,
            'bust' => null,
            'waist' => null,
            'hip' => null,
            'cup' => null,
            'blood_type' => 'A',
            'blood_rh' => '+',
            'debut_date' => null,
            'location' => null,
        ], $payload['normalized_profile']);
        self::assertArrayNotHasKey('blood_type', $payload);
        self::assertArrayNotHasKey('cup', $payload);
        self::assertSame([], $payload['metadata']);

        $performer = $persister->persist($draft);

        self::assertNull($performer->getAttribute('birth_date'));
        self::assertNull($performer->height_cm);
        self::assertNull($performer->bust);
        self::assertNull($performer->waist);
        self::assertNull($performer->hip);
        self::assertNull($performer->cup);
        self::assertSame('A', $performer->blood_type);
        self::assertSame('+', $performer->blood_rh);
        self::assertNull($performer->getAttribute('debut_date'));
        self::assertNull($performer->location);
        Queue::assertPushed(IndexPerformerJob::class, 1);
    }

    public function test_an_invalid_existing_profile_is_cleared_even_when_the_new_draft_is_empty(): void
    {
        $existing = Performer::factory()->create([
            'source_slug' => 'avjoho',
            'external_id' => '2',
            'cup' => 'Q',
            'blood_type' => 'Trans',
            'location' => 'N/A',
            'height_cm' => 99,
        ]);
        $updated = app(PerformerPersister::class)->persist(new PerformerDraft(
            sourceSlug: 'avjoho',
            externalId: '2',
        ));

        self::assertSame($existing->id, $updated->id);
        self::assertNull($updated->cup);
        self::assertNull($updated->blood_type);
        self::assertNull($updated->location);
        self::assertNull($updated->height_cm);
    }

    public function test_movie_cast_persistence_cleans_existing_profile_and_source_birth_date(): void
    {
        config(['elasticsearch.enabled' => false]);
        $existing = Performer::factory()->create([
            'source_slug' => 'avjoho',
            'external_id' => 'movie-credit-1',
            'birth_date' => '1899-12-31',
            'height_cm' => 99,
            'cup' => 'Q',
            'blood_type' => 'Trans',
            'location' => 'N/A',
        ]);
        PerformerSource::query()->create([
            'performer_id' => $existing->id,
            'source_performer_id' => $existing->id,
            'site_slug' => 'avjoho',
            'external_id' => 'movie-credit-1',
            'mongo_id' => 'local:' . $existing->id,
            'source_url' => 'https://avjoho.test/performers/movie-credit-1',
            'birth_date' => '1899-12-31',
            'crawled_at' => now(),
        ]);

        app(MoviePersister::class)->persist(MovieDraft::from(
            sourceSlug: 'avjoho',
            sourceUrl: 'https://avjoho.test/movies/AAA-101',
            code: 'AAA-101',
            performers: [new PerformerDraft(sourceSlug: 'avjoho', externalId: 'movie-credit-1')],
        ));

        $updated = $existing->refresh();
        self::assertNull($updated->getAttribute('birth_date'));
        self::assertNull($updated->height_cm);
        self::assertNull($updated->cup);
        self::assertNull($updated->blood_type);
        self::assertNull($updated->location);
        self::assertNull(PerformerSource::query()->firstOrFail()->getAttribute('birth_date'));
    }
}

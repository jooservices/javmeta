<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerMedia;
use App\Models\PerformerSource;
use App\Models\Movie;
use App\Http\Resources\MovieSummaryResource;
use App\Repositories\PerformerRepository;
use App\Data\Crawl\PerformerDraft;
use App\Services\Persist\PerformerPersister;
use App\Services\Auth\ApiKeyService;
use App\Services\Identity\PerformerIdentityService;
use App\Services\Mcp\McpToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PerformerIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('elasticsearch.enabled', false);
    }

    public function test_dry_run_lists_merge_and_leaves_source_records_unmodified(): void
    {
        [$first, $second] = $this->samePersonAcrossSources();

        $this->artisan('performers:backfill-identities', ['--dry-run' => true])
            ->expectsOutputToContain('Would merge source')
            ->assertSuccessful();

        self::assertNull($second->fresh()->canonical_performer_id);
        self::assertSame((int) $second->id, (int) $this->sourceFor('source-b', 'person-b')->performer_id);
        self::assertNull($first->fresh()->canonical_performer_id);
    }

    public function test_dry_run_backfill_query_count_does_not_grow_with_the_catalog(): void
    {
        $countQueries = function (int $performers): int {
            Performer::query()->delete();
            foreach (range(1, $performers) as $number) {
                Performer::factory()->create([
                    'source_slug' => 'source-a',
                    'external_id' => 'bulk-' . $performers . '-' . $number,
                    'name_romaji' => 'Bulk Person ' . $performers . ' ' . $number . ' Unique',
                    'birth_date' => '1990-01-01',
                ]);
            }
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            app(PerformerIdentityService::class)->backfill(true);

            return $queries;
        };

        self::assertSame($countQueries(3), $countQueries(12));
    }

    public function test_backfill_creates_missing_source_links_and_is_repeatable(): void
    {
        $performer = Performer::factory()->create([
            'source_slug' => 'source-a',
            'external_id' => 'person-a',
            'name_romaji' => 'Aoi Sakura',
            'birth_date' => '1990-01-01',
        ]);

        $this->artisan('performers:backfill-identities', ['--dry-run' => true])->assertSuccessful();
        self::assertDatabaseMissing('performer_sources', ['source_performer_id' => $performer->id]);

        $identity = app(PerformerIdentityService::class);
        self::assertSame(['merges' => [], 'reviews' => []], $identity->backfill());
        self::assertDatabaseHas('performer_sources', [
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'site_slug' => 'source-a',
            'name_romaji' => 'Aoi Sakura',
        ]);
        self::assertSame('1990-01-01', $this->sourceFor('source-a', 'person-a')->birth_date?->toDateString());
        self::assertSame(['merges' => [], 'reviews' => []], $identity->backfill());
        self::assertDatabaseCount('performer_sources', 1);
    }

    public function test_backfill_is_idempotent_and_split_can_be_undone(): void
    {
        [$first, $second] = $this->samePersonAcrossSources();
        $movie = Movie::factory()->create();
        $movie->performers()->attach((int) $second->id, ['source_slug' => 'source-b', 'crawled_at' => now()]);
        $alias = PerformerAlias::query()->create([
            'performer_id' => $second->id,
            'source_performer_id' => $second->id,
            'source_slug' => 'source-b',
            'alias' => 'Sakura Aoi Stage Name',
            'kind' => 'source',
        ]);
        $media = PerformerMedia::query()->create([
            'performer_id' => $second->id,
            'source_performer_id' => $second->id,
            'kind' => PerformerMedia::KIND_IMAGE,
            'url' => 'https://source-b.test/person-b.jpg',
            'meta' => null,
            'source_slug' => 'source-b',
            'crawled_at' => now(),
        ]);
        $identity = app(PerformerIdentityService::class);

        $firstRun = $identity->backfill();
        self::assertCount(1, $firstRun['merges']);
        self::assertSame((int) $first->id, (int) $second->fresh()->canonical_performer_id);
        self::assertSame((int) $first->id, (int) $this->sourceFor('source-b', 'person-b')->performer_id);
        self::assertCount(2, $first->fresh()->sources);
        self::assertSame((int) $first->id, (int) $alias->fresh()->performer_id);
        self::assertSame((int) $first->id, (int) $media->fresh()->performer_id);
        self::assertDatabaseHas('movie_performers', ['movie_id' => $movie->id, 'performer_id' => $first->id]);

        self::assertSame([], $identity->backfill()['merges']);
        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->firstOrFail();
        $split = $identity->split((int) $merge->id);
        self::assertNull($second->fresh()->canonical_performer_id);
        self::assertSame((int) $second->id, (int) $this->sourceFor('source-b', 'person-b')->performer_id);
        self::assertSame((int) $second->id, (int) $alias->fresh()->performer_id);
        self::assertSame((int) $second->id, (int) $media->fresh()->performer_id);
        self::assertDatabaseMissing('movie_performers', ['movie_id' => $movie->id, 'performer_id' => $first->id]);

        $identity->undoSplit((int) $split->id);
        self::assertSame((int) $first->id, (int) $second->fresh()->canonical_performer_id);
        self::assertSame((int) $first->id, (int) $this->sourceFor('source-b', 'person-b')->performer_id);
        self::assertSame((int) $first->id, (int) $alias->fresh()->performer_id);
        self::assertSame((int) $first->id, (int) $media->fresh()->performer_id);
        self::assertDatabaseHas('movie_performers', ['movie_id' => $movie->id, 'performer_id' => $first->id]);
    }

    public function test_merge_preserves_same_alias_from_distinct_source_records(): void
    {
        $first = $this->sourcePerformer('source-a', 'person-a', 'Aoi Sakura', '1990-01-01');
        $second = $this->sourcePerformer('source-a', 'person-b', 'Sakura Aoi', '1990-01-01');
        foreach ([$first, $second] as $performer) {
            $performer->aliases()->create([
                'alias' => 'Aoi',
                'kind' => 'source',
                'source_slug' => 'source-a',
                'source_performer_id' => $performer->id,
            ]);
            PerformerMedia::query()->create([
                'performer_id' => $performer->id,
                'source_performer_id' => $performer->id,
                'kind' => PerformerMedia::KIND_IMAGE,
                'url' => 'https://source-a.test/shared-image.jpg',
                'meta' => null,
                'source_slug' => 'source-a',
                'crawled_at' => now(),
            ]);
        }

        $result = app(PerformerIdentityService::class)->backfill();

        self::assertCount(1, $result['merges']);
        self::assertSame(2, $first->fresh()->aliases()->where('alias', 'Aoi')->count());
        self::assertSame(2, $first->fresh()->media()->where('url', 'https://source-a.test/shared-image.jpg')->count());
    }

    public function test_same_name_with_conflicting_birth_dates_stays_separate_and_is_reported(): void
    {
        $first = $this->sourcePerformer('source-a', 'person-a', 'Mika Aoi', '1990-01-01');
        $second = $this->sourcePerformer('source-b', 'person-b', 'Mika Aoi', '1986-01-01');

        $result = app(PerformerIdentityService::class)->backfill();

        self::assertSame([], $result['merges']);
        self::assertCount(1, $result['reviews']);
        self::assertNull($second->fresh()->canonical_performer_id);
        self::assertTrue((bool) $first->fresh()->needs_review);
        self::assertTrue((bool) $second->fresh()->needs_review);

        $createdAt = (string) DB::table('performer_identity_reviews')->value('created_at');
        try {
            Carbon::setTestNow(Carbon::now()->addDay());
            app(PerformerIdentityService::class)->backfill();
        } finally {
            Carbon::setTestNow();
        }
        self::assertSame($createdAt, (string) DB::table('performer_identity_reviews')->value('created_at'));

        $this->artisan('performers:identity-report')->expectsOutputToContain('birth_date_missing_or_conflicting')->assertSuccessful();
    }

    public function test_retired_and_renamed_sources_merge_by_cross_link_and_keep_both_names(): void
    {
        $retired = $this->sourcePerformer('source-a', 'old-person', 'Ami Natsuki', '1990-01-01');
        $retired->retired_date = '2020-12-31';
        $retired->save();
        $renamed = $this->sourcePerformer('source-b', 'new-person', 'Mina Hope', '1990-01-01');
        foreach ([$retired, $renamed] as $performer) {
            $source = $this->sourceFor((string) $performer->source_slug, (string) $performer->external_id);
            $source->fill(['cross_link_source' => 'shared-catalog', 'cross_link_id' => 'performer-42']);
            $source->save();
        }

        $result = app(PerformerIdentityService::class)->backfill();

        self::assertCount(1, $result['merges']);
        self::assertSame((int) $retired->id, (int) $renamed->fresh()->canonical_performer_id);
        self::assertEqualsCanonicalizing(
            ['Ami Natsuki', 'Mina Hope'],
            $retired->fresh()->sources()->pluck('name_romaji')->all(),
        );
    }

    public function test_performer_persistence_keeps_missing_source_fields_and_previous_names(): void
    {
        $persister = app(PerformerPersister::class);
        $first = $persister->persistPerformer(new PerformerDraft(
            sourceSlug: 'source-a',
            externalId: 'person-a',
            nameRomaji: 'Ami Natsuki',
            nameKanji: '夏樹あみ',
            profileUrl: 'https://source-a.test/person-a',
            imageUrl: 'https://source-a.test/person-a.jpg',
            birthDate: '1990-01-01',
            aliases: ['Ami'],
            crossLinkSource: 'shared-catalog',
            crossLinkId: 'person-42',
        ));

        $updated = $persister->persistPerformer(new PerformerDraft(
            sourceSlug: 'source-a',
            externalId: 'person-a',
            nameRomaji: 'Mina Hope',
            aliases: ['Mina'],
        ));

        $source = $this->sourceFor('source-a', 'person-a');
        self::assertSame((int) $first->id, (int) $updated->id);
        self::assertSame('Mina Hope', $source->name_romaji);
        self::assertSame('夏樹あみ', $source->name_kanji);
        self::assertSame('1990-01-01', $source->birth_date?->toDateString());
        self::assertSame('shared-catalog', $source->cross_link_source);
        self::assertSame('person-42', $source->cross_link_id);
        self::assertTrue($updated->aliases()->where('alias', 'Ami Natsuki')->exists());
        self::assertTrue($updated->aliases()->where('alias', 'Ami')->exists());
        self::assertDatabaseHas('performer_media', [
            'performer_id' => $updated->id,
            'source_performer_id' => $updated->id,
            'url' => 'https://source-a.test/person-a.jpg',
        ]);
    }

    public function test_source_removal_keeps_the_canonical_record_and_is_visible_over_rest_and_mcp(): void
    {
        [$first, $second] = $this->samePersonAcrossSources();
        $identity = app(PerformerIdentityService::class);
        $identity->backfill();

        self::assertTrue($identity->markSourceRemoved('source-b', 'person-b'));
        self::assertSame(1, Performer::query()->whereNull('canonical_performer_id')->count());

        $apiKey = app(ApiKeyService::class)->create('performer-identity')->plaintext;
        $rest = $this->getJson('/api/v1/performers/' . $first->id, ['X-API-Key' => $apiKey])
            ->assertOk()->json('data');
        $mcp = app(McpToolService::class)->call('get_performer', ['id' => (string) $second->id]);

        self::assertCount(2, $rest['sources']);
        self::assertFalse(collect($rest['sources'])->firstWhere('source_slug', 'source-b')['active']);
        self::assertSame($rest['sources'], $mcp['sources']);
        self::assertSame((int) $first->id, (int) $mcp['id']);
    }

    public function test_movie_api_returns_one_canonical_performer_with_all_source_records(): void
    {
        [$first, $second] = $this->samePersonAcrossSources();
        $movie = Movie::factory()->create();
        $movie->performers()->attach((int) $first->id, ['source_slug' => 'source-a', 'crawled_at' => now()]);
        $movie->performers()->attach((int) $second->id, ['source_slug' => 'source-b', 'crawled_at' => now()]);

        app(PerformerIdentityService::class)->backfill();

        $movie->load(['performers.aliases', 'performers.sources']);
        $result = (new MovieSummaryResource($movie))->toArray(Request::create('/'));

        self::assertCount(1, $result['performers']);
        self::assertSame((int) $first->id, (int) $result['performers'][0]['id']);
        self::assertCount(2, $result['performers'][0]['sources']);

        $searchHits = app(PerformerRepository::class)->findByUuidsForDisplay([
            (string) $second->uuid,
            (string) $first->uuid,
        ]);
        self::assertCount(1, $searchHits);
        self::assertSame((int) $first->id, (int) $searchHits->first()->id);
        self::assertCount(2, $searchHits->first()->sources);
    }

    public function test_split_and_undo_reject_stale_identity_groups(): void
    {
        $first = $this->sourcePerformer('source-a', 'person-a', 'Different Name', '1979-01-01');
        $second = $this->sourcePerformer('source-b', 'person-b', 'Mika Aoi', '1986-01-01');
        $third = $this->sourcePerformer('source-c', 'person-c', 'Mika Aoi', '1986-01-01');
        $identity = app(PerformerIdentityService::class);

        self::assertCount(1, $identity->backfill()['merges']);
        $firstMerge = PerformerIdentityEvent::query()->where('event_type', 'merge')->orderBy('id')->firstOrFail();

        foreach ([$first, $second] as $performer) {
            $source = $this->sourceFor((string) $performer->source_slug, (string) $performer->external_id);
            $source->fill(['cross_link_source' => 'shared-catalog', 'cross_link_id' => 'person-42']);
            $source->save();
        }

        self::assertCount(1, $identity->backfill()['merges']);
        $laterMerge = PerformerIdentityEvent::query()->where('event_type', 'merge')->orderByDesc('id')->firstOrFail();

        try {
            $identity->split((int) $firstMerge->id);
            self::fail('A prior merge must not be split while its recorded canonical performer is merged again.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Split later merge events first', $exception->getMessage());
        }
        self::assertSame((int) $first->id, (int) $second->fresh()->canonical_performer_id);
        self::assertSame((int) $first->id, (int) $third->fresh()->canonical_performer_id);

        $split = $identity->split((int) $laterMerge->id);
        foreach ([$first, $second] as $performer) {
            $source = $this->sourceFor((string) $performer->source_slug, (string) $performer->external_id);
            $source->fill(['cross_link_source' => null, 'cross_link_id' => null]);
            $source->save();
        }

        $fourth = $this->sourcePerformer('source-d', 'person-d', 'Mika Aoi', '1986-01-01');
        self::assertCount(1, $identity->backfill()['merges']);
        self::assertSame((int) $second->id, (int) $fourth->fresh()->canonical_performer_id);

        try {
            $identity->undoSplit((int) $split->id);
            self::fail('Undo must not overwrite a performer group that changed after the split.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Restore later identity changes', $exception->getMessage());
        }

        self::assertSame((int) $second->id, (int) $fourth->fresh()->canonical_performer_id);
        self::assertSame((int) $second->id, (int) $this->sourceFor('source-d', 'person-d')->performer_id);
    }

    /** @return array{Performer, Performer} */
    private function samePersonAcrossSources(): array
    {
        return [
            $this->sourcePerformer('source-a', 'person-a', 'Aoi Sakura', '1990-01-01'),
            $this->sourcePerformer('source-b', 'person-b', 'Sakura Aoi', '1990-01-01'),
        ];
    }

    private function sourcePerformer(string $source, string $externalId, string $name, string $birthDate): Performer
    {
        $performer = Performer::factory()->create([
            'source_slug' => $source,
            'external_id' => $externalId,
            'name_romaji' => $name,
            'birth_date' => $birthDate,
        ]);
        PerformerSource::query()->create([
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'site_slug' => $source,
            'mongo_id' => $source . ':' . $externalId,
            'external_id' => $externalId,
            'source_url' => 'https://' . $source . '.test/' . $externalId,
            'name_romaji' => $name,
            'birth_date' => $birthDate,
            'crawled_at' => now(),
        ]);

        return $performer;
    }

    private function sourceFor(string $source, string $externalId): PerformerSource
    {
        return PerformerSource::query()->where('site_slug', $source)->where('external_id', $externalId)->firstOrFail();
    }
}

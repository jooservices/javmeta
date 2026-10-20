<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Data\Crawl\MovieDraft;
use App\Data\Crawl\PerformerDraft;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerMedia;
use App\Models\PerformerSource;
use App\Repositories\MovieRepository;
use App\Repositories\PerformerRepository;
use App\Services\Identity\PerformerIdentityService;
use App\Services\Persist\GalleryPersister;
use App\Services\Persist\MoviePersister;
use App\Services\Persist\PerformerPersister;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\PhotoDto;
use Tests\TestCase;

final class PerformerIdentityIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('elasticsearch.enabled', false);
    }

    public function test_movie_ingestion_merges_strong_matches_into_one_canonical_performer(): void
    {
        $first = $this->persistMovie('SSIS-001', 'javdb', [
            PerformerDraft::from('javdb', 'p1', 'Aoi Sakura', birthDate: '1990-01-01'),
        ]);
        $second = $this->persistMovie('SSIS-002', 'onejav', [
            PerformerDraft::from('onejav', 'x9', 'Sakura Aoi', birthDate: '1990-01-01'),
        ]);

        $roots = Performer::query()->whereNull('canonical_performer_id')->get();
        self::assertCount(1, $roots);
        self::assertCount(2, $roots->first()->sources);
        self::assertSame(1, PerformerIdentityEvent::query()->where('event_type', 'merge')->count());
        self::assertSame([(int) $roots->first()->id], $first->performers()->pluck('performers.id')->map(fn($id) => (int) $id)->all());
        self::assertSame([(int) $roots->first()->id], $second->performers()->pluck('performers.id')->map(fn($id) => (int) $id)->all());
    }

    public function test_movie_ingestion_merges_on_a_numeric_cross_link_id_and_keeps_source_names(): void
    {
        $this->persistMovie('SSIS-001', 'javdb', [
            PerformerDraft::from('javdb', 'p1', 'Aoi Sakura', crossLinkSource: 'catalog', crossLinkId: '42'),
        ]);
        $this->persistMovie('SSIS-002', 'onejav', [
            PerformerDraft::from('onejav', 'x9', 'Completely Different', crossLinkSource: 'catalog', crossLinkId: '42'),
        ]);

        $root = Performer::query()->whereNull('canonical_performer_id')->sole();
        self::assertSame(['Aoi Sakura', 'Completely Different'], $root->sources->pluck('name_romaji')->sort()->values()->all());

        // A merge creates no alias from source names, so search must look at the source records.
        $repository = app(PerformerRepository::class);
        self::assertSame(1, $repository->countFiltered(['q' => 'Completely Different']));
        $movies = app(MovieRepository::class)->search(['actress' => 'Completely Different'], 'release_date', null, 10);
        self::assertSame(2, $movies['total']);
    }

    public function test_movie_ingestion_keeps_a_name_only_match_separate_and_records_a_review(): void
    {
        $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura')]);
        $this->persistMovie('SSIS-002', 'onejav', [PerformerDraft::from('onejav', 'x9', 'Aoi Sakura', birthDate: '1990-01-01')]);

        self::assertSame(2, Performer::query()->whereNull('canonical_performer_id')->count());
        self::assertSame(0, PerformerIdentityEvent::query()->count());
        self::assertDatabaseHas('performer_identity_reviews', ['reason' => 'birth_date_missing_or_conflicting', 'status' => 'open']);
        self::assertSame(2, Performer::query()->where('needs_review', true)->count());
    }

    public function test_crawl_path_does_not_scan_weak_names_but_backfill_reports_them(): void
    {
        $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakurai')]);
        $this->persistMovie('SSIS-002', 'onejav', [PerformerDraft::from('onejav', 'x9', 'Aoi Sakura')]);

        self::assertDatabaseCount('performer_identity_reviews', 0);

        $result = app(PerformerIdentityService::class)->backfill();
        self::assertSame([], $result['merges']);
        self::assertSame(['weak_name_match'], array_column($result['reviews'], 'reason'));
    }

    public function test_gallery_ingestion_reconciles_performers_and_records_reviews(): void
    {
        $existing = Performer::factory()->create([
            'source_slug' => 'javdb',
            'external_id' => 'p1',
            'name_romaji' => 'Aoi Sakura',
            'birth_date' => '1990-01-01',
        ]);
        app(PerformerPersister::class)->persistPerformer(PerformerDraft::from('javdb', 'p1', 'Aoi Sakura', birthDate: '1990-01-01'));

        $this->persistGallery('eporner', ['Aoi Sakura']);

        self::assertSame(2, Performer::query()->whereNull('canonical_performer_id')->count());
        self::assertDatabaseHas('performer_identity_reviews', ['reason' => 'birth_date_missing_or_conflicting']);

        // Once the gallery source record is known to share the birth date, a later gallery crawl merges it.
        PerformerSource::query()->where('site_slug', 'eporner')->update(['birth_date' => '1990-01-01']);
        $this->persistGallery('eporner', ['Aoi Sakura']);

        $root = Performer::query()->whereNull('canonical_performer_id')->sole();
        self::assertSame((int) $existing->id, (int) $root->id);
        self::assertCount(2, $root->sources);
        self::assertSame(1, PerformerMedia::query()->where('performer_id', $root->id)->count());
    }

    public function test_split_returns_post_merge_rows_to_the_split_off_root(): void
    {
        $first = $this->persistOne('src-a', 'a', 'Mika Aoi', '1986-01-01', 'cat', '1');
        $second = $this->persistOne('src-b', 'b', 'Ryo Doe', '1979-01-01');
        $third = $this->persistOne('src-c', 'c', 'Ryo Doe', '1979-01-01');
        self::assertSame((int) $second->id, (int) $third->fresh()->canonical_performer_id);

        // The 'b' and 'a' source records now share a cross-link: the group {b, c} merges under 'a'.
        $this->persistOne('src-b', 'b', 'Ryo Doe', '1979-01-01', 'cat', '1');
        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->orderByDesc('id')->firstOrFail();
        self::assertSame((int) $second->id, (int) $merge->from_performer_id);
        self::assertSame((int) $first->id, (int) $merge->to_performer_id);

        // Rows added after the merge by the child source 'c' sit on the canonical root.
        PerformerAlias::query()->create([
            'performer_id' => $first->id, 'source_performer_id' => $third->id, 'source_slug' => 'src-c',
            'alias' => 'Late Alias', 'kind' => 'source',
        ]);
        $media = PerformerMedia::query()->create([
            'performer_id' => $first->id, 'source_performer_id' => $third->id, 'kind' => PerformerMedia::KIND_IMAGE,
            'url' => 'https://c.test/late.jpg', 'meta' => null, 'source_slug' => 'src-c', 'crawled_at' => now(),
        ]);

        app(PerformerIdentityService::class)->split((int) $merge->id);

        self::assertSame((int) $second->id, (int) PerformerAlias::query()->where('alias', 'Late Alias')->value('performer_id'));
        self::assertSame((int) $second->id, (int) $media->fresh()->performer_id);
        self::assertNull($second->fresh()->canonical_performer_id);
        self::assertSame((int) $second->id, (int) $third->fresh()->canonical_performer_id);
    }

    public function test_movie_links_made_between_merge_and_split_are_restored_on_the_split_off_root(): void
    {
        $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura', birthDate: '1990-01-01')]);
        $this->persistMovie('SSIS-002', 'onejav', [PerformerDraft::from('onejav', 'x9', 'Aoi Sakura', birthDate: '1990-01-01')]);
        $late = $this->persistMovie('SSIS-003', 'onejav', [PerformerDraft::from('onejav', 'x9', 'Aoi Sakura', birthDate: '1990-01-01')]);
        $sourceOnly = Performer::query()->where('source_slug', 'onejav')->sole();
        $rootId = (int) $sourceOnly->canonical_performer_id;
        self::assertDatabaseHas('movie_performers', ['movie_id' => $late->id, 'performer_id' => $rootId]);
        self::assertDatabaseHas('movie_performers', ['movie_id' => $late->id, 'performer_id' => $sourceOnly->id]);

        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->firstOrFail();
        app(PerformerIdentityService::class)->split((int) $merge->id);

        // The split-off record regains the movies it linked before and after the merge. The canonical record keeps
        // links it re-made itself, see docs/03-operations/performer-identity.md.
        self::assertNull($sourceOnly->fresh()->canonical_performer_id);
        foreach (['SSIS002', 'SSIS003'] as $normalized) {
            $movie = Movie::query()->where('code_normalized', $normalized)->sole();
            self::assertContains((int) $sourceOnly->id, $movie->performers()->pluck('performers.id')->map(fn($id) => (int) $id)->all());
        }
    }

    public function test_split_keeps_a_canonical_movie_link_remade_after_the_merge(): void
    {
        $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura', birthDate: '1990-01-01')]);
        $shared = $this->persistMovie('SSIS-002', 'onejav', [PerformerDraft::from('onejav', 'x9', 'Aoi Sakura', birthDate: '1990-01-01')]);
        $rootId = (int) Performer::query()->where('source_slug', 'javdb')->value('id');
        self::assertDatabaseHas('movie_performers', ['movie_id' => $shared->id, 'performer_id' => $rootId]);

        // The canonical performer's own source claims the same movie after the merge, colliding with the merge-created pivot.
        app(MoviePersister::class)->persist(MovieDraft::from(
            sourceSlug: 'javdb',
            sourceUrl: 'https://javdb.test/SSIS-002',
            code: 'SSIS-002',
            performers: [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura', birthDate: '1990-01-01')],
            crawledAt: CarbonImmutable::parse('2026-09-19 09:00:00'),
        ));

        $merge = PerformerIdentityEvent::query()->where('event_type', 'merge')->firstOrFail();
        app(PerformerIdentityService::class)->split((int) $merge->id);

        self::assertDatabaseHas('movie_performers', ['movie_id' => $shared->id, 'performer_id' => $rootId]);
    }

    public function test_movie_ingestion_keeps_previous_source_names_as_aliases(): void
    {
        $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura')]);
        $this->persistMovie('SSIS-002', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Mio Tanaka')]);

        $performer = Performer::query()->where('source_slug', 'javdb')->sole();
        self::assertSame('Mio Tanaka', PerformerSource::query()->where('site_slug', 'javdb')->sole()->name_romaji);
        self::assertDatabaseHas('performer_aliases', [
            'performer_id' => $performer->id,
            'alias' => 'Aoi Sakura',
            'kind' => 'renamed',
        ]);
    }

    public function test_movie_and_gallery_ingestion_index_the_canonical_performer(): void
    {
        config()->set([
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => false,
            'elasticsearch.host' => 'http://elasticsearch.test',
        ]);
        Http::fake(static fn() => Http::response(['result' => 'created']));

        $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura')]);
        $this->persistGallery('eporner', ['Mio Tanaka']);

        $index = (string) config('elasticsearch.performers_index');
        foreach (['javdb', 'eporner'] as $slug) {
            $needle = '/' . $index . '/_doc/' . (string) Performer::query()->where('source_slug', $slug)->value('uuid');
            Http::assertSent(static fn(Request $request): bool => str_contains($request->url(), $needle));
        }
    }

    public function test_rolled_back_crawl_sends_no_elasticsearch_write_and_committed_state_is_indexed(): void
    {
        config()->set([
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => false,
            'elasticsearch.host' => 'http://elasticsearch.test',
        ]);
        Http::fake(static fn() => Http::response(['result' => 'created']));

        try {
            DB::transaction(function (): void {
                $this->persistMovie('SSIS-001', 'javdb', [PerformerDraft::from('javdb', 'p1', 'Aoi Sakura')]);
                throw new \RuntimeException('crawl failed');
            });
            self::fail('The transaction should have rolled back.');
        } catch (\RuntimeException) {
        }

        Http::assertNothingSent();

        DB::transaction(fn() => $this->persistMovie('SSIS-002', 'javdb', [PerformerDraft::from('javdb', 'p2', 'Mio Tanaka')]));

        $needle = '/' . (string) config('elasticsearch.performers_index') . '/_doc/' . (string) Performer::query()->value('uuid');
        Http::assertSent(static fn(Request $request): bool => str_contains($request->url(), $needle));
    }

    public function test_reconciliation_returns_the_actual_root_after_a_concurrent_merge(): void
    {
        $keeper = $this->persistOne('javdb', 'p1', 'Aoi Sakura', '1990-01-01');
        $other = Performer::factory()->create(['source_slug' => 'x', 'external_id' => 'x1', 'name_romaji' => 'Someone Else']);
        $incoming = $this->persistOne('onejav', 'x9', 'Zzz Unrelated', '1980-05-05');
        $incomingId = (int) $incoming->id;
        // Make the incoming source strongly match the keeper, then simulate another worker merging it away first.
        $source = PerformerSource::query()->where('site_slug', 'onejav')->sole();
        $source->update(['name_romaji' => 'Aoi Sakura', 'birth_date' => '1990-01-01']);

        $fired = false;
        DB::beforeExecuting(function (string $query) use (&$fired, $incomingId, $other): void {
            if ($fired || DB::transactionLevel() === 0 || ! str_contains($query, 'from "performers" where "performers"."id" = ?')) {
                return;
            }
            $fired = true;
            DB::table('performers')->where('id', $incomingId)->update(['canonical_performer_id' => $other->id]);
        });

        $root = app(PerformerIdentityService::class)->reconcileSource($source);

        self::assertTrue($fired);
        self::assertSame($root, app(PerformerIdentityService::class)->canonicalId($incomingId));
        self::assertSame($root, app(PerformerIdentityService::class)->canonicalId((int) $keeper->id));
    }

    public function test_performer_persister_uses_the_local_performer_id_as_the_fallback_archive_key(): void
    {
        $persister = app(PerformerPersister::class);
        $draft = PerformerDraft::from('javdb', '12345', 'Aoi Sakura');
        $performer = Performer::factory()->create(['source_slug' => 'javdb', 'external_id' => '12345']);

        // The archive returns no id when MongoDB is unavailable; the key must not depend on the numeric external id.
        $method = new \ReflectionMethod($persister, 'linkAndIndex');
        $method->invoke($persister, $performer, $draft, null, CarbonImmutable::now());

        self::assertSame('local:' . $performer->id, PerformerSource::query()->where('site_slug', 'javdb')->value('mongo_id'));
    }

    public function test_backfill_candidate_query_does_not_bind_every_linked_id(): void
    {
        Performer::factory()->count(3)->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(PerformerIdentityService::class)->backfill(true);

        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('not in (', strtolower((string) $query['query']));
        }
    }

    /** @param list<PerformerDraft> $performers */
    private function persistMovie(string $code, string $source, array $performers): Movie
    {
        return app(MoviePersister::class)->persist(MovieDraft::from(
            sourceSlug: $source,
            sourceUrl: 'https://' . $source . '.test/' . $code,
            code: $code,
            performers: $performers,
            crawledAt: CarbonImmutable::parse('2026-09-18 09:00:00'),
        ));
    }

    /** @param list<string> $names */
    private function persistGallery(string $source, array $names): void
    {
        app(GalleryPersister::class)->persist($source, 'https://' . $source . '.test/g1', new GalleryDto(
            externalId: 'g1',
            title: 'Gallery',
            performers: $names,
            photos: [new PhotoDto(id: 'ph1', imageUrl: 'https://' . $source . '.test/p1.jpg', thumbnailUrl: null, position: 1)],
            metadata: [],
        ));
    }

    private function persistOne(string $source, string $externalId, string $name, string $birthDate, ?string $crossLinkSource = null, ?string $crossLinkId = null): Performer
    {
        return app(PerformerPersister::class)->persistPerformer(PerformerDraft::from(
            $source,
            $externalId,
            $name,
            birthDate: $birthDate,
            crossLinkSource: $crossLinkSource,
            crossLinkId: $crossLinkId,
        ));
    }
}

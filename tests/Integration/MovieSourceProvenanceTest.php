<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Data\Crawl\MovieDraft;
use App\Models\MovieSource;
use App\Services\Persist\MoviePersister;
use Carbon\CarbonImmutable;

final class MovieSourceProvenanceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('elasticsearch.enabled', false);
    }

    public function test_recrawl_during_mongo_outage_preserves_the_existing_archive_reference(): void
    {
        $draft = $this->draft('SSIS-001');
        $movie = $this->persister()->persist($draft);
        $source = MovieSource::query()->where('movie_id', $movie->id)->where('site_slug', 'javdb')->sole();
        $mongoId = $source->mongo_id;

        self::assertNotEmpty($mongoId);

        config()->set('mongodb.uri', 'mongodb://127.0.0.1:1');

        $this->persister()->persist($draft);

        self::assertSame($mongoId, $source->refresh()->mongo_id);
    }

    public function test_changed_code_moves_the_archive_reference_to_the_new_movie(): void
    {
        $oldMovie = $this->persister()->persist($this->draft('SSIS-001'));
        $oldSource = MovieSource::query()->where('movie_id', $oldMovie->id)->where('site_slug', 'javdb')->sole();
        $mongoId = $oldSource->mongo_id;

        $newMovie = $this->persister()->persist($this->draft('ABP-001'));

        self::assertNotSame($oldMovie->id, $newMovie->id);
        self::assertSame(2, MovieSource::query()->count());
        self::assertNull($oldSource->refresh()->mongo_id);
        self::assertSame($mongoId, MovieSource::query()
            ->where('movie_id', $newMovie->id)
            ->where('site_slug', 'javdb')
            ->sole()
            ->mongo_id);
    }

    private function persister(): MoviePersister
    {
        return $this->app->make(MoviePersister::class);
    }

    private function draft(string $code): MovieDraft
    {
        return MovieDraft::from(
            sourceSlug: 'javdb',
            sourceUrl: 'https://javdb.test/v/provenance',
            code: $code,
            crawledAt: CarbonImmutable::parse('2026-11-09 09:00:00'),
        );
    }
}

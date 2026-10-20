<?php

declare(strict_types=1);

namespace Tests\Feature\Persist;

use App\Data\Crawl\MovieDraft;
use App\Data\Crawl\PerformerDraft;
use App\Jobs\IndexMovieJob;
use App\Jobs\IndexPerformerJob;
use App\Models\Movie;
use App\Models\Performer;
use App\Services\Persist\MoviePersister;
use App\Services\Persist\PerformerPersister;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class SearchIndexDispatchTest extends TestCase
{
    use DatabaseMigrations;

    protected bool $fakeSearchIndexJobs = false;

    public function test_movie_indexing_is_queued_after_commit_without_calling_the_indexer_inline(): void
    {
        Http::fake();
        config([
            'elasticsearch.enabled' => true,
            'queue.default' => 'database',
        ]);

        DB::beginTransaction();
        $movie = app(MoviePersister::class)->persist($this->movieDraft());

        self::assertSame(0, DB::table('jobs')->where('queue', 'search')->count());
        DB::commit();

        self::assertSame(1, DB::table('jobs')->where('queue', 'search')->count());
        Http::assertNothingSent();
        $payload = json_decode((string) DB::table('jobs')->where('queue', 'search')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(IndexMovieJob::class, $payload['displayName']);
        $job = unserialize($payload['data']['command'], ['allowed_classes' => [IndexMovieJob::class]]);
        self::assertInstanceOf(IndexMovieJob::class, $job);
        self::assertSame($movie->id, $job->movieId);
        self::assertSame('search', $job->queue);
    }

    public function test_performer_indexing_is_queued_after_commit_without_calling_the_indexer_inline(): void
    {
        Http::fake();
        config([
            'elasticsearch.enabled' => true,
            'queue.default' => 'database',
        ]);

        DB::beginTransaction();
        $performer = app(PerformerPersister::class)->persist($this->performerDraft());

        self::assertSame(0, DB::table('jobs')->where('queue', 'search')->count());
        DB::commit();

        self::assertSame(1, DB::table('jobs')->where('queue', 'search')->count());
        Http::assertNothingSent();
        $payload = json_decode((string) DB::table('jobs')->where('queue', 'search')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(IndexPerformerJob::class, $payload['displayName']);
        $job = unserialize($payload['data']['command'], ['allowed_classes' => [IndexPerformerJob::class]]);
        self::assertInstanceOf(IndexPerformerJob::class, $job);
        self::assertSame($performer->id, $job->performerId);
        self::assertSame('search', $job->queue);
    }

    public function test_search_switch_off_skips_movie_and_performer_jobs(): void
    {
        Queue::fake();
        config(['elasticsearch.enabled' => false]);

        app(MoviePersister::class)->persist($this->movieDraft());
        app(PerformerPersister::class)->persist($this->performerDraft());

        Queue::assertNothingPushed();
    }

    public function test_index_jobs_are_unique_per_entity_and_retry_with_backoff(): void
    {
        Queue::fake();

        IndexMovieJob::dispatch(21)->onQueue('search');
        IndexMovieJob::dispatch(21)->onQueue('search');
        IndexMovieJob::dispatch(22)->onQueue('search');
        IndexPerformerJob::dispatch(21)->onQueue('search');
        IndexPerformerJob::dispatch(21)->onQueue('search');

        Queue::assertPushed(IndexMovieJob::class, 2);
        Queue::assertPushed(IndexPerformerJob::class, 1);
        self::assertSame(3, (new IndexMovieJob(1))->tries);
        self::assertSame([10, 60], (new IndexMovieJob(1))->backoff());
        self::assertSame(3, (new IndexPerformerJob(1))->tries);
        self::assertSame([10, 60], (new IndexPerformerJob(1))->backoff());
    }

    public function test_index_jobs_skip_disabled_search_and_missing_entities(): void
    {
        Http::fake();
        config(['elasticsearch.enabled' => false]);

        (new IndexMovieJob(999))->handle(app(ElasticsearchIndexer::class));
        (new IndexPerformerJob(999))->handle(app(ElasticsearchIndexer::class));

        config(['elasticsearch.enabled' => true]);
        (new IndexMovieJob(999))->handle(app(ElasticsearchIndexer::class));
        (new IndexPerformerJob(999))->handle(app(ElasticsearchIndexer::class));

        Http::assertNothingSent();
    }

    public function test_index_jobs_complete_when_the_indexer_succeeds(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => false,
            'elasticsearch.host' => 'http://elasticsearch.test',
        ]);
        $movie = Movie::factory()->create();
        $performer = Performer::factory()->create();

        (new IndexMovieJob((int) $movie->id))->handle(app(ElasticsearchIndexer::class));
        (new IndexPerformerJob((int) $performer->id))->handle(app(ElasticsearchIndexer::class));

        Http::assertSentCount(2);
    }

    public function test_failed_movie_indexing_throws_so_the_queue_retries_it(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => false,
            'elasticsearch.host' => 'http://elasticsearch.test',
        ]);
        $movie = Movie::factory()->create();

        $this->expectException(RuntimeException::class);

        (new IndexMovieJob((int) $movie->id))->handle(app(ElasticsearchIndexer::class));
    }

    public function test_failed_performer_indexing_throws_so_the_queue_retries_it(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => false,
            'elasticsearch.host' => 'http://elasticsearch.test',
        ]);
        $performer = Performer::factory()->create();

        $this->expectException(RuntimeException::class);

        (new IndexPerformerJob((int) $performer->id))->handle(app(ElasticsearchIndexer::class));
    }

    private function movieDraft(): MovieDraft
    {
        return MovieDraft::from(
            sourceSlug: 'javdb',
            sourceUrl: 'https://javdb.test/v/1',
            code: 'SSIS-001',
            titleJp: 'Japanese title',
            titleEn: 'English title',
            extras: ['magnets' => [], 'hls' => [], 'gallery' => [], 'score' => null],
        );
    }

    private function performerDraft(): PerformerDraft
    {
        return PerformerDraft::from(
            sourceSlug: 'javdb',
            externalId: 'performer-1',
            nameRomaji: 'Performer Name',
            profileUrl: 'https://javdb.test/performer/1',
        );
    }
}

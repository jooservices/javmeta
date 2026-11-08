<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CrawlQueue;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieCredit;
use App\Models\MovieGenre;
use App\Models\MoviePerformer;
use App\Models\MovieSource;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RelationshipsTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, class-string<Relation>, class-string}> */
    public static function relations(): iterable
    {
        yield 'movie genre -> movie' => [MovieGenre::class, 'movie', BelongsTo::class, Movie::class];
        yield 'movie genre -> genre' => [MovieGenre::class, 'genre', BelongsTo::class, Genre::class];
        yield 'movie performer -> movie' => [MoviePerformer::class, 'movie', BelongsTo::class, Movie::class];
        yield 'movie performer -> performer' => [MoviePerformer::class, 'performer', BelongsTo::class, Performer::class];
        yield 'genre -> movies' => [Genre::class, 'movies', BelongsToMany::class, Movie::class];
        yield 'movie credit -> movie' => [MovieCredit::class, 'movie', BelongsTo::class, Movie::class];
        yield 'movie source -> movie' => [MovieSource::class, 'movie', BelongsTo::class, Movie::class];
        yield 'performer alias -> performer' => [PerformerAlias::class, 'performer', BelongsTo::class, Performer::class];
        yield 'performer source -> performer' => [PerformerSource::class, 'performer', BelongsTo::class, Performer::class];
        yield 'performer source -> source performer' => [PerformerSource::class, 'sourcePerformer', BelongsTo::class, Performer::class];
    }

    /**
     * @param class-string $model
     * @param class-string<Relation> $type
     * @param class-string $related
     */
    #[DataProvider('relations')]
    public function test_each_relation_points_at_its_related_model(string $model, string $method, string $type, string $related): void
    {
        $relation = (new $model())->{$method}();

        self::assertInstanceOf($type, $relation);
        self::assertInstanceOf($related, $relation->getRelated());
    }

    /** @return iterable<string, array{?string, string, string}> */
    public static function crawlKinds(): iterable
    {
        yield 'listing' => ['listing', 'movie', CrawlQueue::KIND_LISTING];
        yield 'detail' => ['detail', 'performer', CrawlQueue::KIND_DETAIL];
        yield 'performer listing' => ['performer_listing', 'movie', CrawlQueue::KIND_PERFORMER_LISTING];
        yield 'performer detail' => ['performer_detail', 'movie', CrawlQueue::KIND_PERFORMER_DETAIL];
        yield 'gallery' => ['gallery', 'movie', CrawlQueue::KIND_GALLERY];
        yield 'unknown step falls back to the performer entity' => [null, 'performer', CrawlQueue::KIND_PERFORMER_DETAIL];
        yield 'unknown step falls back to the gallery entity' => ['other', 'gallery', CrawlQueue::KIND_GALLERY];
        yield 'unknown step and entity fall back to detail' => ['other', 'movie', CrawlQueue::KIND_DETAIL];
    }

    #[DataProvider('crawlKinds')]
    public function test_the_next_crawl_type_maps_to_a_queue_kind(?string $next, string $entity, string $expected): void
    {
        self::assertSame($expected, CrawlQueue::kindForNextCrawlType($next, $entity));
    }
}

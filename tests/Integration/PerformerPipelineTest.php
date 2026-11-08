<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use App\Models\Performer;
use App\Models\PerformerSource;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Live-captured performer page → real crawlerx → javmeta job → Postgres
 * (performer + source link) + Mongo archive + Elasticsearch document.
 */
final class PerformerPipelineTest extends IntegrationTestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function performerFixtures(): array
    {
        return self::crawlerxFixtures(CrawlQueue::KIND_PERFORMER_DETAIL);
    }

    #[DataProvider('performerFixtures')]
    public function test_performer_page_is_stored_in_postgres_mongo_and_elasticsearch(string $slug, string $kind, string $url, string $bodyPath): void
    {
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, $kind);
        $this->respondWithFixture($url, $bodyPath);

        $this->dispatchFor($row);

        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_DONE, $row->status, "row failed: {$row->last_error}");

        $link = PerformerSource::query()->where('site_slug', $slug)->sole();
        self::assertNotEmpty($link->mongo_id);
        $performer = Performer::query()->findOrFail($link->performer_id);

        $archived = $this->mongoCollection($slug)->findOne(['_id' => new ObjectId((string) $link->mongo_id)]);
        self::assertNotNull($archived, 'Mongo archive document missing');
        self::assertSame('performer', $archived['entity_type']);

        $document = $this->esDocument((string) config('elasticsearch.performers_index'), (string) $performer->uuid);
        self::assertNotNull($document, 'Elasticsearch performer document missing');
        self::assertSame($performer->id, $document['performer_id']);
    }
}

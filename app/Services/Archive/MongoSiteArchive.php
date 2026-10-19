<?php

declare(strict_types=1);

namespace App\Services\Archive;

use Carbon\CarbonImmutable;
use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use App\Observability\ObservabilityEmitter;
use RuntimeException;
use Throwable;

/**
 * Writes flexible per-site crawl payloads to MongoDB (archive only; not end-user read).
 * Collection name = site slug (onejav, javdb, …).
 */
final class MongoSiteArchive
{
    private ?Client $client = null;

    public function __construct(
        private readonly MongoArchiveWriteHealth $writeHealth,
        private readonly MongoArchiveFailureReporter $failureReporter,
        private readonly ObservabilityEmitter $observability,
    ) {}

    public function upsertMovie(string $siteSlug, string $url, array $payload, ?string $code = null): ?string
    {
        return $this->upsert($siteSlug, 'movie', [
            'entity_type' => 'movie',
            'url' => $url,
            'code' => $code,
            'payload' => $payload,
            'parse_ok' => true,
            'crawled_at' => new UTCDateTime(CarbonImmutable::now()),
        ], ['url' => $url]);
    }

    public function upsertPerformer(string $siteSlug, string $url, array $payload, ?string $externalId = null): ?string
    {
        $filter = $externalId !== null && $externalId !== ''
            ? ['entity_type' => 'performer', 'external_id' => $externalId]
            : ['entity_type' => 'performer', 'url' => $url];

        return $this->upsert($siteSlug, 'performer', [
            'entity_type' => 'performer',
            'url' => $url,
            'external_id' => $externalId,
            'payload' => $payload,
            'parse_ok' => true,
            'crawled_at' => new UTCDateTime(CarbonImmutable::now()),
        ], $filter);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $filter
     */
    private function upsert(string $siteSlug, string $entityType, array $document, array $filter): ?string
    {
        $started = hrtime(true);
        try {
            $client = $this->client();
            $collection = $client->selectDatabase((string) config('mongodb.database'))->selectCollection($siteSlug);
            $result = $collection->updateOne(
                $filter,
                ['$set' => $document],
                ['upsert' => true],
            );

            $id = null;
            if ($result->getUpsertedId() instanceof ObjectId) {
                $id = (string) $result->getUpsertedId();
            } else {
                $existing = $collection->findOne($filter, ['projection' => ['_id' => 1]]);
                if ($existing !== null && isset($existing['_id'])) {
                    $id = (string) $existing['_id'];
                }
            }

            if ($id === null) {
                throw new RuntimeException('Mongo archive upsert did not return a document ID.');
            }

            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->writeHealth->recordSuccess();
            $this->observability->emitDependency('mongo', 'upsert', true, $durationMs);

            return $id;
        } catch (Throwable $exception) {
            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->writeHealth->recordFailure();
            $this->failureReporter->report($exception, $siteSlug, $entityType);
            $this->observability->emitDependency('mongo', 'upsert', false, $durationMs);

            return null;
        }
    }

    private function client(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        if (! class_exists(Client::class) || ! extension_loaded('mongodb')) {
            throw new RuntimeException('MongoDB driver is unavailable.');
        }

        $uri = config('mongodb.uri');
        if (! is_string($uri) || $uri === '') {
            $host = (string) config('mongodb.host', 'mongo');
            $port = (int) config('mongodb.port', 27017);
            $uri = "mongodb://{$host}:{$port}";
        }

        return $this->client = new Client($uri, [], ['serverSelectionTimeoutMS' => 1500]);
    }
}

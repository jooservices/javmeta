<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Crawler\SourceLoginCookieStore;

final class SourceLoginCookieStoreTest extends IntegrationTestCase
{
    public function test_cookies_are_encrypted_at_rest_in_mongo(): void
    {
        $store = app(SourceLoginCookieStore::class);
        $value = fake()->sha256();

        $store->put('javdb', ['sid' => $value]);

        $document = $this->mongo()
            ->selectDatabase((string) config('database.connections.mongodb.database'))
            ->selectCollection('configs')
            ->findOne(['group' => SourceLoginCookieStore::GROUP, 'key' => 'javdb']);

        self::assertNotNull($document);
        self::assertSame('encrypted', $document['type'] ?? null);
        self::assertIsString($document['value'] ?? null);
        self::assertStringNotContainsString($value, (string) json_encode($document));
        self::assertSame(['sid' => $value], $store->cookies('javdb'));

        $store->forget('javdb');
        self::assertSame([], $store->cookies('javdb'));
    }
}

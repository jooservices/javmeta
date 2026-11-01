<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Services\Normalize\SourceNormalizerRegistry;
use App\Services\Persist\MoviePersister;
use Carbon\CarbonImmutable;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;

final class ProductCodeRecoveryTest extends IntegrationTestCase
{
    public function test_scoped_products_recrawl_and_failed_rows_requeue_in_postgres(): void
    {
        $products = [
            ['javbtc', 'softondemand-marina-shiraishi-atmania-stepmom'],
            ['ffjav', '10musume-052425_01'],
            ['141jav', 'C2897'],
            ['tokyohot', 'Japanese_Invasion_2_America_Sc4'],
            ['tokyohot', 'Shemalesoapland_Sc4'],
        ];
        $persister = app(MoviePersister::class);
        foreach ($products as [$slug, $product]) {
            $prefix = match ($slug) {
                'javbtc' => 'r18',
                'tokyohot' => 'product',
                default => 'torrent',
            };
            $url = 'https://example.test/' . $prefix . '/' . $product;
            $normalizer = app(SourceNormalizerRegistry::class)->forSlug($slug);
            $draft = $normalizer->normalizeMovie(
                new MovieDto(title: 'Product title', externalId: $product),
                [],
                $slug,
                $url,
                CarbonImmutable::parse('2026-11-01'),
            );
            $first = $persister->persist($draft);
            $second = $persister->persist($draft, isRecrawl: true);
            self::assertSame($first->getKey(), $second->getKey());
        }
        self::assertSame(5, Movie::query()->count());

        $row = CrawlQueue::factory()->create([
            'source_slug' => 'javbtc', 'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_FAILED, 'error_code' => 'parse_failed', 'attempts' => 5,
            'last_error' => 'Missing product code', 'last_failed_at' => CarbonImmutable::parse('2026-11-01'),
        ]);
        $this->artisan('crawler:requeue-product-codes', ['--dry-run' => true])
            ->expectsOutput('javbtc: 1 would requeue')->assertSuccessful();
        self::assertSame(CrawlQueue::STATUS_FAILED, $row->refresh()->status);
        $this->artisan('crawler:requeue-product-codes')->assertSuccessful();
        self::assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
        self::assertSame(0, $row->attempts);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Downloads;

use App\Models\Source;
use App\Support\Downloads\DownloadSearchPacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DownloadSearchPacerTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_fetch_returns_busy_when_reserve_is_null(): void
    {
        $source = Source::factory()->create(['slug' => 'onejav', 'enabled' => true]);
        $pacer = new DownloadSearchPacer(
            app(\App\Services\Crawl\SourceRequestPacer::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            static fn(): ?int => null,
            static function (): void {},
        );

        self::assertSame('source_busy', $pacer->beforeFetch($source, 'onejav', 'download_search', 45));
    }

    public function test_before_fetch_waits_when_reserve_returns_positive_delay(): void
    {
        $source = Source::factory()->create(['slug' => 'onejav', 'enabled' => true]);
        $waited = 0;
        $pacer = new DownloadSearchPacer(
            app(\App\Services\Crawl\SourceRequestPacer::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            static fn(): ?int => 12,
            static function (int $microseconds) use (&$waited): void {
                $waited = $microseconds;
            },
        );

        self::assertNull($pacer->beforeFetch($source, 'onejav', 'download_search', 45));
        self::assertSame(12, $waited);
    }

    public function test_before_fetch_skips_wait_when_slot_is_immediate(): void
    {
        $source = Source::factory()->create(['slug' => 'onejav', 'enabled' => true]);
        $waited = false;
        $pacer = new DownloadSearchPacer(
            app(\App\Services\Crawl\SourceRequestPacer::class),
            app(\App\Services\Crawl\SourceThrottle::class),
            static fn(): ?int => 0,
            static function () use (&$waited): void {
                $waited = true;
            },
        );

        self::assertNull($pacer->beforeFetch($source, 'onejav', 'download_search', 45));
        self::assertFalse($waited);
    }
}

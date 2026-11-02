<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Services\Crawl\CrawlerxFetchMethodPlan;
use App\Services\Crawl\FetchMethodPreference;
use Illuminate\Support\Facades\Cache;
use JOOservices\CrawlerX\Enums\FetchMethod;
use Tests\TestCase;

final class CrawlerxFetchMethodPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        config()->set([
            'javmeta_queues.fetch_method_window_size' => 10,
            'javmeta_queues.fetch_method_failure_threshold' => 8,
            'javmeta_queues.fetch_method_window_ttl_seconds' => 3600,
            'javmeta_queues.fetch_method_preference_ttl_seconds' => 3600,
            'javmeta_queues.fetch_method_probe_interval' => 10,
        ]);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();

        parent::tearDown();
    }

    public function test_healthy_source_keeps_its_default_crawlerx_method_order(): void
    {
        $options = app(CrawlerxFetchMethodPlan::class)->optionsFor(
            'javbus',
            'https://www.javbus.com/abc',
            150,
        );

        self::assertNull($options->fetch?->chain);
    }

    public function test_flare_is_first_during_learning_and_playwright_is_probed_every_tenth_request(): void
    {
        $preference = app(FetchMethodPreference::class);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $preference->recordAttempts('javbus', 'www.javbus.com', [
                ['method' => 'playwright', 'outcome' => 'error'],
                ['method' => 'flaresolverr', 'outcome' => 'ok'],
            ]);
        }

        $plan = app(CrawlerxFetchMethodPlan::class);
        $options = $plan->optionsFor('javbus', 'https://www.javbus.com/abc', 150);

        self::assertSame(
            [FetchMethod::Flaresolverr, FetchMethod::Playwright],
            $options->fetch?->chain?->methods,
        );

        for ($request = 1; $request < 9; $request++) {
            $plan->optionsFor('javbus', 'https://www.javbus.com/abc', 150);
        }
        $probe = $plan->optionsFor('javbus', 'https://www.javbus.com/abc', 150);

        self::assertSame(
            [FetchMethod::Playwright, FetchMethod::Flaresolverr],
            $probe->fetch?->chain?->methods,
        );
    }
}

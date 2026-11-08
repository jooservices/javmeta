<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Services\Crawl\FetchMethodPreference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class FetchMethodPreferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:34:00Z'));
        config()->set('cache.default', 'array');
        config()->set([
            'javmeta_queues.fetch_method_window_size' => 4,
            'javmeta_queues.fetch_method_failure_threshold' => 3,
            'javmeta_queues.fetch_method_window_ttl_seconds' => 3600,
            'javmeta_queues.fetch_method_preference_ttl_seconds' => 60,
            'javmeta_queues.fetch_method_probe_interval' => 3,
        ]);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();

        parent::tearDown();
    }

    public function test_flare_preference_requires_a_full_failure_window_and_a_recent_flare_success(): void
    {
        $preference = app(FetchMethodPreference::class);

        foreach ([false, true, false, false] as $playwrightSucceeded) {
            $attempts = [
                ['method' => 'playwright', 'outcome' => $playwrightSucceeded ? 'ok' : 'error'],
                ['method' => 'flaresolverr', 'outcome' => $playwrightSucceeded ? 'error' : 'error'],
            ];
            $preference->recordAttempts('javbus', 'www.javbus.com', $attempts);
        }

        self::assertFalse($preference->beforeFetch('javbus', 'www.javbus.com')->prefersFlaresolverr);

        $preference->recordAttempts('javbus', 'www.javbus.com', [
            ['method' => 'playwright', 'outcome' => 'error'],
            ['method' => 'flaresolverr', 'outcome' => 'ok'],
        ]);

        self::assertTrue($preference->beforeFetch('javbus', 'www.javbus.com')->prefersFlaresolverr);
        self::assertFalse($preference->beforeFetch('javbus', 'javbus.com')->prefersFlaresolverr);
    }

    public function test_flare_success_expires_from_its_rolling_window(): void
    {
        config()->set('javmeta_queues.fetch_method_failure_threshold', 4);
        $preference = app(FetchMethodPreference::class);
        $preference->recordAttempts('javbus', 'www.javbus.com', [
            ['method' => 'playwright', 'outcome' => 'ok'],
            ['method' => 'flaresolverr', 'outcome' => 'ok'],
        ]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $preference->recordAttempts('javbus', 'www.javbus.com', [
                ['method' => 'playwright', 'outcome' => 'error'],
                ['method' => 'flaresolverr', 'outcome' => 'error'],
            ]);
        }

        self::assertFalse($preference->beforeFetch('javbus', 'www.javbus.com')->prefersFlaresolverr);
    }

    public function test_attempts_older_than_the_window_ttl_do_not_trigger_a_preference(): void
    {
        config()->set([
            'javmeta_queues.fetch_method_window_size' => 2,
            'javmeta_queues.fetch_method_failure_threshold' => 2,
            'javmeta_queues.fetch_method_window_ttl_seconds' => 60,
            'javmeta_queues.fetch_method_preference_ttl_seconds' => 3600,
        ]);
        $preference = app(FetchMethodPreference::class);
        $preference->recordAttempts('javbus', 'www.javbus.com', [
            ['method' => 'playwright', 'outcome' => 'ok'],
            ['method' => 'flaresolverr', 'outcome' => 'ok'],
        ]);

        $this->travel(61)->seconds();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $preference->recordAttempts('javbus', 'www.javbus.com', [
                ['method' => 'playwright', 'outcome' => 'error'],
                ['method' => 'flaresolverr', 'outcome' => 'error'],
            ]);
        }

        self::assertFalse($preference->beforeFetch('javbus', 'www.javbus.com')->prefersFlaresolverr);
    }

    public function test_preference_probes_playwright_and_expires_without_extending_its_ttl(): void
    {
        config()->set([
            'javmeta_queues.fetch_method_window_size' => 1,
            'javmeta_queues.fetch_method_failure_threshold' => 1,
            'javmeta_queues.fetch_method_probe_interval' => 3,
        ]);
        $preference = app(FetchMethodPreference::class);
        $preference->recordAttempts('javbus', 'www.javbus.com', [
            ['method' => 'playwright', 'outcome' => 'error'],
            ['method' => 'flaresolverr', 'outcome' => 'ok'],
        ]);

        $first = $preference->beforeFetch('javbus', 'www.javbus.com');
        $second = $preference->beforeFetch('javbus', 'www.javbus.com');
        $probe = $preference->beforeFetch('javbus', 'www.javbus.com');

        self::assertTrue($first->prefersFlaresolverr);
        self::assertFalse($first->probePlaywright);
        self::assertFalse($second->probePlaywright);
        self::assertTrue($probe->probePlaywright);

        $this->travel(61)->seconds();

        self::assertFalse($preference->beforeFetch('javbus', 'www.javbus.com')->prefersFlaresolverr);
    }

    public function test_a_successful_playwright_probe_restores_the_normal_method_order(): void
    {
        config()->set([
            'javmeta_queues.fetch_method_window_size' => 1,
            'javmeta_queues.fetch_method_failure_threshold' => 1,
        ]);
        $preference = app(FetchMethodPreference::class);
        $preference->recordAttempts('javbus', 'www.javbus.com', [
            ['method' => 'playwright', 'outcome' => 'error'],
            ['method' => 'flaresolverr', 'outcome' => 'ok'],
        ]);

        $preference->recordAttempts('javbus', 'www.javbus.com', [
            ['method' => 'playwright', 'outcome' => 'ok'],
        ]);

        self::assertFalse($preference->beforeFetch('javbus', 'www.javbus.com')->prefersFlaresolverr);
    }
}

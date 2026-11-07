<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\Source;
use App\Services\Crawl\SourceCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SourceCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    public function test_consecutive_block_responses_pause_a_source_with_exponential_automatic_resume(): void
    {
        config([
            'javmeta_sources.defaults.circuit.failure_threshold' => 3,
            'javmeta_sources.defaults.circuit.cooldown_seconds' => 10,
            'javmeta_sources.defaults.circuit.max_cooldown_seconds' => 40,
        ]);
        $source = Source::factory()->create(['slug' => 'javbus']);
        $breaker = app(SourceCircuitBreaker::class);
        Event::fake();

        $breaker->recordFailure($source->slug, CrawlErrorCode::Blocked);
        $breaker->recordFailure($source->slug, CrawlErrorCode::RateLimited);
        $this->assertSame('closed', $source->refresh()->circuit_state);
        $breaker->recordFailure($source->slug, CrawlErrorCode::Challenge);

        $source->refresh();
        $this->assertSame('open', $source->circuit_state);
        $this->assertSame(3, $source->consecutive_failures);
        $this->assertSame(1, $source->circuit_open_count);
        $this->assertSame(10, $source->circuit_cooldown_seconds);
        $this->assertSame([$source->slug], $breaker->pausedSourceSlugs());
        Event::assertDispatched(\App\Events\SourceCircuitOpened::class, static fn($event): bool => $event->sourceSlug === 'javbus'
            && $event->consecutiveBlocks === 3
            && $event->cooldownSeconds === 10);

        $breaker->recordFailure($source->slug, CrawlErrorCode::Blocked);
        $source->refresh();
        $this->assertSame(4, $source->consecutive_failures);
        $this->assertSame(1, $source->circuit_open_count, 'An in-flight block does not restart an active cooldown.');

        $this->travel(11)->seconds();
        $this->assertSame([], $breaker->pausedSourceSlugs());
        $this->assertSame('closed', $source->refresh()->circuit_state);

        $breaker->recordFailure($source->slug, CrawlErrorCode::Blocked);
        $source->refresh();
        $this->assertSame('open', $source->circuit_state);
        $this->assertSame(20, $source->circuit_cooldown_seconds);

        $this->travel(21)->seconds();
        $this->assertSame([], $breaker->pausedSourceSlugs());
        $breaker->recordFailure($source->slug, CrawlErrorCode::Blocked);
        $source->refresh();
        $this->assertSame(40, $source->circuit_cooldown_seconds, 'Exponential cooldown is capped by the configured maximum.');

        $breaker->recordSuccess($source->slug);
        $source->refresh();
        $this->assertSame('closed', $source->circuit_state);
        $this->assertSame(0, $source->consecutive_failures);
        $this->assertSame(0, $source->circuit_open_count);
        $this->assertNull($source->circuit_cooldown_seconds);
        Event::assertDispatchedTimes(\App\Events\SourceCircuitOpened::class, 3);
    }

    public function test_non_block_failures_do_not_trip_the_block_circuit(): void
    {
        $source = Source::factory()->create(['slug' => 'javdb', 'consecutive_failures' => 2]);
        app(SourceCircuitBreaker::class)->recordFailure($source->slug, CrawlErrorCode::Timeout);

        $source->refresh();
        $this->assertSame('closed', $source->circuit_state);
        $this->assertSame(0, $source->consecutive_failures);
    }
}

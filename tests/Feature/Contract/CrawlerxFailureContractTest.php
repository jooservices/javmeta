<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\CrawlEvent;
use App\Models\CrawlQueue;
use App\Models\Movie;
use JOOservices\Client\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Generated failure responses go through the real crawlerx stack and the
 * javmeta detail job. The expectations pin the crawlerx 1.3 contract: terminal
 * codes (not_found, gone, auth_required) fail the row at once; retryable codes
 * put it back to pending with crawlerx's Retry-After hint or javmeta's backoff.
 */
final class CrawlerxFailureContractTest extends CrawlerxContractTestCase
{
    private const SLUG = 'onejav';

    private const URL = 'https://onejav.com/torrent/ymds282';

    /** @return array<string, array{\Closure(): (ResponseInterface|Throwable), string, string}> */
    public static function terminalFailures(): array
    {
        return [
            'not found 404' => [static fn() => TestResponse::make(404, [], fake()->sentence()), 'not_found', CrawlEvent::KIND_SOFT404],
            'gone 410' => [static fn() => TestResponse::make(410, [], fake()->sentence()), 'gone', CrawlEvent::KIND_SOFT404],
            'auth required 401' => [static fn() => TestResponse::make(401, [], fake()->sentence()), 'auth_required', CrawlEvent::KIND_AUTH],
        ];
    }

    /** @return array<string, array{\Closure(): (ResponseInterface|Throwable), string, string, int}> */
    public static function retryableFailures(): array
    {
        $challenge = '<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>';
        $noMovieFields = static fn() => TestResponse::make(200, ['Content-Type' => 'text/html'], '<html><body><p>' . fake()->paragraph() . '</p></body></html>');

        return [
            'rate limited 429 with Retry-After' => [static fn() => TestResponse::make(429, ['Retry-After' => '120'], fake()->sentence()), 'rate_limited', CrawlEvent::KIND_BLOCKED, 120],
            'unavailable 503 with Retry-After' => [static fn() => TestResponse::make(503, ['Retry-After' => '30'], fake()->sentence()), 'rate_limited', CrawlEvent::KIND_BLOCKED, 30],
            'server error 500' => [static fn() => TestResponse::make(500, [], fake()->sentence()), 'network', CrawlEvent::KIND_BLOCKED, 60],
            'empty 200 body' => [static fn() => TestResponse::make(200, [], ''), 'network', CrawlEvent::KIND_BLOCKED, 60],
            'network error' => [static fn() => new RuntimeException(fake()->sentence()), 'network', CrawlEvent::KIND_BLOCKED, 60],
            'cloudflare challenge 403' => [static fn() => TestResponse::make(403, ['cf-mitigated' => 'challenge', 'Server' => 'cloudflare'], $challenge), 'challenge', CrawlEvent::KIND_CHALLENGE, 60],
            // crawlerx 1.3 treats a page without the adapter's readiness markers as not ready, not as parse drift.
            'page without movie fields' => [$noMovieFields, 'network', CrawlEvent::KIND_BLOCKED, 60],
        ];
    }

    /** @param \Closure(): (ResponseInterface|Throwable) $response */
    #[DataProvider('terminalFailures')]
    public function test_terminal_failure_fails_the_row_without_retry(\Closure $response, string $errorCode, string $eventKind): void
    {
        $row = $this->detailRow(attempts: 1);
        $this->respondWith(self::URL, $response(), $response(), $response());

        $this->dispatchFor($row);

        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_FAILED, $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNotNull($row->last_error);
        $this->assertNothingStored($errorCode, $eventKind);
    }

    /** @param \Closure(): (ResponseInterface|Throwable) $response */
    #[DataProvider('retryableFailures')]
    public function test_retryable_failure_reschedules_the_row(\Closure $response, string $errorCode, string $eventKind, int $delaySeconds): void
    {
        $this->freezeSecond();
        $row = $this->detailRow(attempts: 1);
        $this->respondWith(self::URL, $response(), $response(), $response());

        $this->dispatchFor($row);

        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_PENDING, $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNull($row->locked_by);
        self::assertNotNull($row->last_error);
        self::assertSame(now()->addSeconds($delaySeconds)->getTimestamp(), $row->next_attempt_at?->getTimestamp());
        $this->assertNothingStored($errorCode, $eventKind);
    }

    /**
     * @param \Closure(): (ResponseInterface|Throwable) $response
     */
    #[DataProvider('retryableFailures')]
    public function test_retryable_failure_at_max_attempts_fails_the_row(\Closure $response, string $errorCode, string $eventKind, int $delaySeconds): void
    {
        $row = $this->detailRow(attempts: 3);
        $this->respondWith(self::URL, $response(), $response(), $response());

        $this->dispatchFor($row);

        $row->refresh();
        self::assertSame(CrawlQueue::STATUS_FAILED, $row->status);
        self::assertSame(3, $row->attempts);
        $this->assertNothingStored($errorCode, $eventKind);
    }

    private function detailRow(int $attempts): CrawlQueue
    {
        $this->source(self::SLUG);
        $row = $this->claimedRow(self::SLUG, self::URL, CrawlQueue::KIND_DETAIL);
        $row->forceFill(['attempts' => $attempts])->save();

        return $row;
    }

    private function assertNothingStored(string $errorCode, string $eventKind): void
    {
        self::assertSame(0, Movie::query()->count());
        $event = CrawlEvent::query()->where('source_slug', self::SLUG)->sole();
        self::assertSame($eventKind, $event->kind);
        self::assertSame($errorCode, $event->detail['error_code'] ?? null);
    }
}

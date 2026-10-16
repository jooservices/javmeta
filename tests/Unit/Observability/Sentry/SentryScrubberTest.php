<?php

declare(strict_types=1);

namespace Tests\Unit\Observability\Sentry;

use App\Observability\Sentry\SentryScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Tests\TestCase;

final class SentryScrubberTest extends TestCase
{
    #[DataProvider('requestShapes')]
    public function test_before_send_drops_request_secrets_and_strips_url_query(array $request): void
    {
        config()->set(['sentry.role' => 'api', 'sentry.node' => 'node-a']);
        $event = Event::createEvent()->setRequest($request);

        $scrubbed = SentryScrubber::beforeSend($event);

        self::assertNotNull($scrubbed);
        self::assertSame([
            'url' => 'https://catalog.example.test/items',
            'method' => 'GET',
        ], $scrubbed->getRequest());
        self::assertSame(['role' => 'api', 'node' => 'node-a'], $scrubbed->getTags());
    }

    public static function requestShapes(): iterable
    {
        yield 'Laravel request data and headers' => [[
            'url' => 'https://catalog.example.test/items?api_key=private&sort=recent#page',
            'method' => 'GET',
            'query_string' => 'api_key=private&sort=recent',
            'headers' => ['Authorization' => 'Bearer private', 'X-Api-Key' => 'private'],
            'cookies' => ['session' => 'private'],
            'data' => ['password' => 'private'],
            'env' => ['HTTP_COOKIE' => 'session=private'],
        ]];

        yield 'URL credentials and alternate query' => [[
            'url' => 'https://user:password@catalog.example.test/items?token=private',
            'method' => 'GET',
            'query_string' => 'token=private',
            'headers' => ['Cookie' => 'session=private'],
            'cookies' => ['session' => 'private'],
            'data' => ['api_key' => 'private'],
        ]];
    }

    public function test_before_breadcrumb_keeps_only_allowlisted_metadata_and_drops_message(): void
    {
        $breadcrumb = new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            'GET https://catalog.example.test/items?api_key=private',
            [
                'method' => 'GET',
                'status_code' => 200,
                'url' => 'https://catalog.example.test/items?token=private',
                'headers' => ['Authorization' => 'Bearer private'],
                'cookies' => ['session' => 'private'],
                'query_string' => 'token=private',
                'data' => ['api_key' => 'private'],
                'api_key' => 'private',
                'status' => ['unexpected' => 'private'],
            ],
        );

        $scrubbed = SentryScrubber::beforeBreadcrumb($breadcrumb);

        self::assertNotNull($scrubbed);
        self::assertNull($scrubbed->getMessage());
        self::assertSame([
            'method' => 'GET',
            'status_code' => 200,
            'url' => 'https://catalog.example.test/items',
        ], $scrubbed->getMetadata());
    }

    public function test_before_send_redacts_exception_credentials_and_removes_user_and_extra_data(): void
    {
        $exception = new RuntimeException('Request failed: Bearer abc123 token=xyz https://user:pass@example.test/path?q=private');
        $event = Event::createEvent()
            ->setMessage('api_key=private')
            ->setUser(new \Sentry\UserDataBag(null, 'person@example.test'))
            ->setExtra(['cookie' => 'private'])
            ->setExceptions([new ExceptionDataBag($exception)]);

        $scrubbed = SentryScrubber::beforeSend($event);

        self::assertNotNull($scrubbed);
        self::assertNull($scrubbed->getUser());
        self::assertSame([], $scrubbed->getExtra());
        self::assertArrayNotHasKey('api_key', $scrubbed->getTags());
        self::assertStringNotContainsString('abc123', $scrubbed->getExceptions()[0]->getValue());
        self::assertStringNotContainsString('xyz', $scrubbed->getExceptions()[0]->getValue());
        self::assertStringNotContainsString('q=private', $scrubbed->getExceptions()[0]->getValue());
        self::assertSame('api_key=[Filtered]', $scrubbed->getMessage());
    }

    public function test_before_send_clears_frame_variables_and_safely_strips_malformed_urls(): void
    {
        $stacktrace = new \Sentry\Stacktrace([
            new \Sentry\Frame('run', '/app/Job.php', 20, vars: ['token' => 'private']),
        ]);
        $event = Event::createEvent()
            ->setRequest(['url' => 'http://[broken?token=private'])
            ->setExceptions([new ExceptionDataBag(new RuntimeException('failed'), $stacktrace)]);

        $scrubbed = SentryScrubber::beforeSend($event);

        self::assertNotNull($scrubbed);
        self::assertSame(['url' => 'http://[broken'], $scrubbed->getRequest());
        self::assertSame([], $scrubbed->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars());
    }
}

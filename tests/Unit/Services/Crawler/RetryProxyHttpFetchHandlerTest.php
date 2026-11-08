<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\RetryProxyHttpFetchHandler;
use JOOservices\CrawlerX\Contracts\LoginCookieProvider;
use JOOservices\CrawlerX\Dto\CrawlOptionsDto;
use JOOservices\CrawlerX\Dto\HttpOptionsDto;
use JOOservices\CrawlerX\Dto\SiteProfileDto;
use JOOservices\CrawlerX\Enums\FetchMethod;
use JOOservices\CrawlerX\Fetch\Session\CookieHandoffStore;
use JOOservices\CrawlerX\Fetch\Session\SessionStore;
use JOOservices\CrawlerX\Registry\FileAdapterManifestRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(RetryProxyHttpFetchHandler::class)]
final class RetryProxyHttpFetchHandlerTest extends TestCase
{
    /** @var resource|null */
    private $proxyProcess = null;
    private string $routerFile = '';
    private int $port = 0;

    protected function tearDown(): void
    {
        if (is_resource($this->proxyProcess)) {
            proc_terminate($this->proxyProcess);
            proc_close($this->proxyProcess);
        }
        if ($this->routerFile !== '' && is_file($this->routerFile)) {
            unlink($this->routerFile);
        }
        parent::tearDown();
    }

    public function test_only_the_plain_http_method_is_supported(): void
    {
        $handler = $this->handler('http://127.0.0.1:1');

        self::assertTrue($handler->supports(FetchMethod::Http));
        self::assertFalse($handler->supports(FetchMethod::Playwright));
        self::assertFalse($handler->supports(FetchMethod::Flaresolverr));
    }

    public function test_an_unreachable_proxy_returns_a_failed_result_with_an_error_code(): void
    {
        $result = $this->handler('http://127.0.0.1:1')->fetch('http://retry.test/page', $this->profile(), FetchMethod::Http);

        self::assertFalse($result->ok);
        self::assertSame(0, $result->status);
        self::assertSame('', $result->body);
        self::assertFalse($result->challengeDetected);
        self::assertSame('http://retry.test/page', $result->finalUrl);
        self::assertNotNull($result->error);
        self::assertGreaterThanOrEqual(0, $result->elapsedMs);
    }

    public function test_a_usable_page_through_the_proxy_is_ok_and_keeps_the_response_headers(): void
    {
        $this->startProxy();

        $result = $this->handler($this->proxyUrl())->fetch('http://retry.test/ok', $this->profile(), FetchMethod::Http);

        self::assertTrue($result->ok);
        self::assertSame(200, $result->status);
        self::assertStringContainsString('usable page body through the retry proxy', $result->body);
        self::assertFalse($result->challengeDetected);
        self::assertNull($result->error);
        self::assertSame('http://retry.test/ok', $result->finalUrl);
        self::assertSame(['yes'], $result->headers['X-Retry-Proxy'] ?? $result->headers['x-retry-proxy'] ?? null);
    }

    public function test_a_challenge_page_is_reported_as_a_challenge_not_as_usable(): void
    {
        $this->startProxy();

        $result = $this->handler($this->proxyUrl())->fetch('http://retry.test/challenge', $this->profile(), FetchMethod::Http);

        self::assertFalse($result->ok);
        self::assertTrue($result->challengeDetected);
        self::assertSame('challenge page', $result->error);
    }

    public function test_an_error_status_is_an_unusable_body_not_a_challenge(): void
    {
        $this->startProxy();

        $result = $this->handler($this->proxyUrl())->fetch('http://retry.test/missing', $this->profile(), FetchMethod::Http);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->status);
        self::assertFalse($result->challengeDetected);
        self::assertSame('unusable HTTP body', $result->error);
    }

    public function test_handed_off_login_and_session_cookies_are_sent_with_the_request(): void
    {
        $this->startProxy();
        $cookies = new CookieHandoffStore();
        $cookies->put('retry.test', ['handoff' => 'one']);
        $logins = new class implements LoginCookieProvider {
            public function cookiesFor(string $site): array
            {
                return ['login' => 'two'];
            }
        };
        $handler = new RetryProxyHttpFetchHandler($this->proxyUrl(), $cookies, new SessionStore(), $logins);

        $result = $handler->fetch('http://retry.test/echo-cookie', $this->profile(), FetchMethod::Http);

        self::assertTrue($result->ok);
        // The session store cookie header (login cookies) replaces the plain hand-off header.
        self::assertStringContainsString('login=two', $result->body);
    }

    public function test_the_hand_off_cookie_is_sent_when_there_is_no_login_provider(): void
    {
        $this->startProxy();
        $cookies = new CookieHandoffStore();
        $cookies->put('retry.test', ['handoff' => 'one']);
        $handler = new RetryProxyHttpFetchHandler($this->proxyUrl(), $cookies, new SessionStore());

        $result = $handler->fetch('http://retry.test/echo-cookie', $this->profile(), FetchMethod::Http);

        self::assertTrue($result->ok);
        self::assertStringContainsString('handoff=one', $result->body);
    }

    public function test_the_method_timeout_option_wins_over_the_profile_timeout(): void
    {
        $this->startProxy();
        $options = new CrawlOptionsDto(http: new HttpOptionsDto(timeout: 20), methodTimeoutSeconds: 15);

        $result = $this->handler($this->proxyUrl())->fetch('http://retry.test/ok', $this->profile(), FetchMethod::Http, $options);
        $withHttpTimeoutOnly = $this->handler($this->proxyUrl())->fetch('http://retry.test/ok', $this->profile(), FetchMethod::Http, new CrawlOptionsDto(http: new HttpOptionsDto(timeout: 20)));

        self::assertTrue($result->ok);
        self::assertTrue($withHttpTimeoutOnly->ok);
    }

    private function handler(string $proxy): RetryProxyHttpFetchHandler
    {
        return new RetryProxyHttpFetchHandler($proxy, new CookieHandoffStore(), new SessionStore());
    }

    private function profile(): SiteProfileDto
    {
        return SiteProfileDto::fromManifest(app(FileAdapterManifestRegistry::class)->get('javbus'));
    }

    private function proxyUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** A tiny forward proxy: PHP's built-in server answers every absolute-URI request through one router. */
    private function startProxy(): void
    {
        $this->routerFile = sys_get_temp_dir() . '/javmeta-retry-proxy-' . uniqid() . '.php';
        file_put_contents($this->routerFile, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('X-Retry-Proxy: yes');
if ($path === '/ok') {
    echo '<html><body>A usable page body through the retry proxy.</body></html>';
} elseif ($path === '/challenge') {
    http_response_code(403);
    echo '<html><head><title>Just a moment...</title></head><body>cf-browser-verification</body></html>';
} elseif ($path === '/echo-cookie') {
    echo '<html><body>cookie=' . ($_SERVER['HTTP_COOKIE'] ?? 'none') . ' padding padding padding</body></html>';
} else {
    http_response_code(404);
    echo 'not found';
}
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, $error);
        $this->port = (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $this->proxyProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->routerFile],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($this->proxyProcess);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return;
            }
            usleep(100_000);
        }
        self::fail('The test proxy did not start.');
    }
}

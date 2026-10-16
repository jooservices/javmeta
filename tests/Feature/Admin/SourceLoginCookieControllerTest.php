<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Logging\ArrayLogStore;
use App\Services\Crawler\LaravelConfigLoginCookieProvider;
use App\Services\Crawler\SourceLoginCookieStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Support\ConfigType;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use JOOservices\LaravelLogging\Contracts\LogStoreInterface;
use Tests\TestCase;

final class SourceLoginCookieControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'javdb';

    private string $ownerToken;

    private FakeConfigStore $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerToken = fake()->uuid();
        config(['javmeta_auth.owner_admin_token' => $this->ownerToken]);
        $this->config = new FakeConfigStore();
        $this->app->instance(ConfigStore::class, $this->config);
    }

    public function test_put_stores_cookies_encrypted_without_echoing_values(): void
    {
        $cookies = ['_session' => fake()->sha256(), 'remember_me' => fake()->uuid()];

        $response = $this->putJson($this->url(), ['cookies' => $cookies], $this->ownerHeaders());

        $response->assertOk()
            ->assertJsonPath('data.source', self::SLUG)
            ->assertJsonPath('data.names', ['_session', 'remember_me'])
            ->assertJsonStructure(['data' => ['updated_at']]);
        foreach ($cookies as $value) {
            self::assertStringNotContainsString($value, (string) $response->getContent());
        }

        $record = $this->config->listOrdered()->firstWhere('key', self::SLUG);
        self::assertSame(SourceLoginCookieStore::GROUP, $record['group'] ?? null);
        self::assertSame(ConfigType::Encrypted->value, $record['type'] ?? null);
        self::assertSame($cookies, (new LaravelConfigLoginCookieProvider(static fn(): SourceLoginCookieStore => app(SourceLoginCookieStore::class)))->cookiesFor(self::SLUG));
    }

    public function test_put_records_an_audit_event_with_names_only(): void
    {
        /** @var ArrayLogStore $store */
        $store = $this->app->make(LogStoreInterface::class);
        $store->flush();
        $value = fake()->sha256();

        $this->putJson($this->url(), ['cookies' => ['sid' => $value]], $this->ownerHeaders())->assertOk();

        $records = $store->all();
        self::assertCount(1, $records);
        self::assertSame('source.login_cookies.updated', $records[0]->action);
        self::assertSame(['sid'], $records[0]->context['cookie_names'] ?? null);
        self::assertStringNotContainsString($value, (string) json_encode($records[0]->context));
    }

    public function test_delete_clears_the_cookies(): void
    {
        app(SourceLoginCookieStore::class)->put(self::SLUG, ['sid' => fake()->sha256()]);

        $this->deleteJson($this->url(), [], $this->ownerHeaders())->assertNoContent();

        self::assertSame([], app(SourceLoginCookieStore::class)->cookies(self::SLUG));
    }

    public function test_invalid_cookie_name_is_rejected_without_echoing_the_value(): void
    {
        $value = fake()->sha256();

        $response = $this->putJson($this->url(), ['cookies' => ['bad name;' => $value]], $this->ownerHeaders());

        $response->assertUnprocessable();
        self::assertStringNotContainsString($value, (string) $response->getContent());
        self::assertSame([], $this->config->all());
    }

    public function test_invalid_cookie_value_is_rejected_without_echoing_it(): void
    {
        $value = fake()->word() . '; Path=/';

        $response = $this->putJson($this->url(), ['cookies' => ['sid' => $value]], $this->ownerHeaders());

        $response->assertUnprocessable();
        self::assertStringNotContainsString($value, (string) $response->getContent());
    }

    public function test_unknown_source_is_rejected(): void
    {
        $this->putJson('/api/v1/admin/sources/' . fake()->unique()->lexify('nosuch??????') . '/login-cookies', [
            'cookies' => ['sid' => fake()->sha256()],
        ], $this->ownerHeaders())->assertUnprocessable();

        self::assertSame([], $this->config->all());
    }

    public function test_missing_owner_token_is_rejected(): void
    {
        $this->putJson($this->url(), ['cookies' => ['sid' => fake()->sha256()]])->assertUnauthorized();
        $this->deleteJson($this->url())->assertUnauthorized();

        self::assertSame([], $this->config->all());
    }

    private function url(): string
    {
        return '/api/v1/admin/sources/' . self::SLUG . '/login-cookies';
    }

    /** @return array<string, string> */
    private function ownerHeaders(): array
    {
        return ['X-Owner-Token' => $this->ownerToken];
    }
}

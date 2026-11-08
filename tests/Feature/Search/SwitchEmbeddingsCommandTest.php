<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Movie;
use App\Models\Performer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use App\Services\Settings\SettingsService;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class SwitchEmbeddingsCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeConfigStore $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new FakeConfigStore();
        $this->app->instance(ConfigStore::class, $this->settings);
        $this->app->forgetInstance(SettingsService::class);
        SettingsService::forgetDefaults();
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    public function test_command_rejects_an_unknown_provider_before_changing_settings(): void
    {
        config(['javmeta_ai.embedding_provider' => 'ollama']);

        $this->artisan('search:switch-embeddings', ['provider' => 'unknown'])
            ->expectsOutputToContain('The provider is embedder or ollama.')
            ->assertFailed();

        self::assertSame('ollama', config('javmeta_ai.embedding_provider'));
    }

    public function test_command_restores_the_provider_when_the_dimension_probe_fails(): void
    {
        config(['javmeta_ai.embedding_provider' => 'ollama']);
        $this->fakeSwitchServices(false);

        $this->artisan('search:switch-embeddings', ['provider' => 'embedder'])
            ->expectsOutputToContain('The embedder provider did not answer; nothing was changed.')
            ->assertFailed();

        self::assertSame('ollama', config('javmeta_ai.embedding_provider'));
        self::assertSame(384, config('elasticsearch.embed_dim'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), 'elasticsearch.test'));
    }

    public function test_dry_run_reports_new_indices_and_restores_the_live_settings(): void
    {
        config([
            'javmeta_ai.embedding_provider' => 'ollama',
            'elasticsearch.movies_index' => 'javmeta_movies_384d_embedder',
            'elasticsearch.performers_index' => 'javmeta_performers_384d_embedder',
        ]);
        $this->fakeSwitchServices();

        $this->artisan('search:switch-embeddings', ['provider' => 'embedder', '--dry-run' => true])
            ->expectsOutputToContain('Plan: provider embedder, 384 dimensions, new indices javmeta_movies_384d_embedder and javmeta_performers_384d_embedder')
            ->assertSuccessful();

        self::assertSame('ollama', config('javmeta_ai.embedding_provider'));
        self::assertSame('javmeta_movies_384d_embedder', config('elasticsearch.movies_index'));
        Http::assertNotSent(static fn(ClientRequest $request): bool => str_contains($request->url(), 'elasticsearch.test'));
    }

    public function test_trial_run_indexes_limited_movies_and_performers_without_switching_settings(): void
    {
        Movie::factory()->create(['title_en' => 'A trial movie']);
        Performer::factory()->create(['name_romaji' => 'Trial Performer']);
        config([
            'javmeta_ai.embedding_provider' => 'ollama',
            'elasticsearch.movies_index' => 'javmeta_movies',
            'elasticsearch.performers_index' => 'javmeta_performers',
        ]);
        $this->fakeSwitchServices();

        $this->artisan('search:switch-embeddings', ['provider' => 'embedder', '--limit' => 1])
            ->expectsOutputToContain('Indexed 2, failed 0.')
            ->expectsOutputToContain('Trial run; the live settings were not switched.')
            ->assertSuccessful();

        self::assertSame('ollama', config('javmeta_ai.embedding_provider'));
        Http::assertSentCount(7);
        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/javmeta_movies_384d_embedder/_doc/' . Movie::query()->firstOrFail()->uuid));
        Http::assertSent(static fn(ClientRequest $request): bool => str_ends_with($request->url(), '/javmeta_performers_384d_embedder/_doc/' . Performer::query()->firstOrFail()->uuid));
    }

    public function test_full_run_switches_to_new_indices_after_both_entities_are_indexed(): void
    {
        Movie::factory()->create(['title_en' => 'A complete movie']);
        Performer::factory()->create(['name_romaji' => 'Complete Performer']);
        config(['javmeta_ai.embedding_provider' => 'embedder']);
        $this->fakeSwitchServices();

        $this->artisan('search:switch-embeddings', ['provider' => 'embedder'])
            ->expectsOutputToContain('Indexed 2, failed 0.')
            ->expectsOutputToContain('Switched to embedder.')
            ->assertSuccessful();

        self::assertSame('javmeta_movies_384d_embedder', config('elasticsearch.movies_index'));
        self::assertSame('javmeta_performers_384d_embedder', config('elasticsearch.performers_index'));
        self::assertSame(384, config('elasticsearch.embed_dim'));
        self::assertSame('embedder', $this->settings->get('settings.ai.embedding_provider'));
        self::assertSame(384, $this->settings->get('settings.search.embedding_dimensions'));
        self::assertSame('javmeta_movies_384d_embedder', $this->settings->get('settings.search.movies_index'));
        self::assertSame('javmeta_performers_384d_embedder', $this->settings->get('settings.search.performers_index'));
    }

    private function fakeSwitchServices(bool $embedderAvailable = true): void
    {
        config([
            'elasticsearch.enabled' => true,
            'elasticsearch.embedder_enabled' => true,
            'elasticsearch.embedder_url' => 'http://embedder.test',
            'elasticsearch.host' => 'http://elasticsearch.test',
            'elasticsearch.username' => '',
            'elasticsearch.password' => '',
            'elasticsearch.api_key' => '',
            'elasticsearch.verify_ssl' => false,
        ]);
        Http::preventStrayRequests();
        Http::fake(static function (ClientRequest $request) use ($embedderAvailable) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return $embedderAvailable
                    ? Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]], 200)
                    : Http::response([], 503);
            }

            return Http::response(['result' => 'created'], 200);
        });
    }
}

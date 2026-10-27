<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Services\Embedding\EmbedderClient;
use App\Services\Embedding\OllamaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OllamaProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['elasticsearch.enabled' => true, 'elasticsearch.embedder_enabled' => true, 'javmeta_ai.ollama_url' => 'http://ollama.test:11434', 'javmeta_ai.ollama_embed_model' => 'nomic-embed-text', 'javmeta_ai.ollama_chat_model' => 'qwen2.5:7b']);
    }

    public function test_the_embedder_provider_is_the_default_and_ollama_is_not_called(): void
    {
        Http::fake(['embedder:8000/*' => Http::response(['data' => [['embedding' => [0.1, 0.2]]]])]);

        $this->assertSame([0.1, 0.2], app(EmbedderClient::class)->embedOne('x'));
        Http::assertNotSent(fn($r) => str_contains($r->url(), 'ollama.test'));
    }

    public function test_with_the_ollama_provider_texts_are_embedded_by_ollama_and_failures_are_null(): void
    {
        config(['javmeta_ai.embedding_provider' => 'ollama']);
        Http::fake(['ollama.test:11434/api/embed' => Http::response(['embeddings' => [[1, 2, 3], [4, 5, 6]]])]);

        $vectors = app(EmbedderClient::class)->embedTexts(['a', 'b']);
        $this->assertSame([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]], $vectors);
        Http::assertSent(fn($r) => $r['model'] === 'nomic-embed-text' && $r['input'] === ['a', 'b']);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['ollama.test:11434/*' => Http::response('boom', 500)]);
        $this->assertNull(app(EmbedderClient::class)->embedOne('x'));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['ollama.test:11434/*' => static fn() => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        $this->assertNull(app(EmbedderClient::class)->embedOne('x'));
    }

    public function test_models_and_json_answers(): void
    {
        Http::fake([
            'ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'nomic-embed-text:latest'], ['name' => 'qwen2.5:7b']]]),
            'ollama.test:11434/api/chat' => Http::response(['message' => ['content' => '{"keywords":["office"],"year":2024}']]),
        ]);
        $ollama = app(OllamaClient::class);

        $this->assertSame(['nomic-embed-text:latest', 'qwen2.5:7b'], $ollama->models());
        $this->assertSame(['keywords' => ['office'], 'year' => 2024], $ollama->json('sys', 'user'));
        Http::assertSent(fn($r) => str_contains($r->url(), '/api/chat') && $r['model'] === 'qwen2.5:7b' && $r['format'] === 'json' && $r['stream'] === false);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['ollama.test:11434/*' => Http::response('nope', 500)]);
        $this->assertNull($ollama->models());
        $this->assertNull($ollama->json('s', 'u'));
    }

    public function test_natural_language_chat_timeout_is_capped_at_three_seconds(): void
    {
        config(['javmeta_ai.nl_timeout_seconds' => 60]);
        $optionsSeen = null;
        Http::fake([
            'ollama.test:11434/api/chat' => static function ($request, array $options) use (&$optionsSeen) {
                $optionsSeen = $options;

                return Http::response(['message' => ['content' => '{}']]);
            },
        ]);

        $this->assertSame([], app(OllamaClient::class)->json('sys', 'user'));
        $this->assertSame(3.0, $optionsSeen['timeout'] ?? null);
        $this->assertSame(3.0, $optionsSeen['connect_timeout'] ?? null);
    }

    public function test_the_check_command_reports_models_and_a_missing_one(): void
    {
        config(['javmeta_ai.embedding_provider' => 'ollama']);
        Http::fake([
            'ollama.test:11434/api/tags' => Http::response(['models' => [['name' => 'nomic-embed-text:latest']]]),
            'ollama.test:11434/api/embed' => Http::response(['embeddings' => [[1, 2, 3, 4]]]),
        ]);

        $this->artisan('ai:check')->expectsOutputToContain('1 models')->expectsOutputToContain('ollama_chat_model qwen2.5:7b: MISSING')->expectsOutputToContain('4 dimensions')->assertSuccessful();
    }

    public function test_the_switch_command_refuses_an_unreachable_provider_and_changes_nothing(): void
    {
        config(['javmeta_ai.embedding_provider' => 'embedder', 'elasticsearch.embed_dim' => 384]);
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);

        $this->artisan('search:switch-embeddings', ['provider' => 'ollama'])->expectsOutputToContain('did not answer')->assertFailed();
        $this->assertSame('embedder', config('javmeta_ai.embedding_provider'));
        $this->assertSame(384, config('elasticsearch.embed_dim'));
        $this->artisan('search:switch-embeddings', ['provider' => 'bogus'])->assertFailed();
    }

    public function test_the_switch_dry_run_names_the_new_indices_and_keeps_the_settings(): void
    {
        config(['javmeta_ai.embedding_provider' => 'embedder', 'elasticsearch.embed_dim' => 384, 'elasticsearch.movies_index' => 'javmeta_movies', 'elasticsearch.performers_index' => 'javmeta_performers']);
        Http::fake(['ollama.test:11434/api/embed' => Http::response(['embeddings' => [array_fill(0, 768, 0.1)]])]);

        $this->artisan('search:switch-embeddings', ['provider' => 'ollama', '--dry-run' => true])->expectsOutputToContain('javmeta_movies_768d_ollama')->assertSuccessful();
        $this->assertSame('embedder', config('javmeta_ai.embedding_provider'));
        $this->assertSame('javmeta_movies', config('elasticsearch.movies_index'));
    }

    public function test_warm_keeps_the_chat_model_loaded_and_the_question_timeout_is_short(): void
    {
        config(['javmeta_ai.nl_timeout_seconds' => 3, 'javmeta_ai.ollama_keep_alive' => '45m']);
        Http::fake(['ollama.test:11434/api/generate' => Http::response(['done' => true]), 'ollama.test:11434/api/chat' => Http::response(['message' => ['content' => '{}']])]);

        $this->artisan('ai:warm')->expectsOutputToContain('qwen2.5:7b: loaded')->assertSuccessful();
        Http::assertSent(fn($r) => str_contains($r->url(), '/api/generate') && $r['model'] === 'qwen2.5:7b' && $r['keep_alive'] === '45m');

        app(OllamaClient::class)->json('s', 'u');
        Http::assertSent(fn($r) => str_contains($r->url(), '/api/chat') && $r['keep_alive'] === '45m');
    }
}

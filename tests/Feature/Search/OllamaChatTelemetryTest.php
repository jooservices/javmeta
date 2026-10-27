<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Services\Embedding\OllamaClient;
use App\Services\Search\SearchBudget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OllamaChatTelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'javmeta_ai.ollama_url' => 'http://ollama.test:11434',
            'javmeta_ai.ollama_chat_model' => 'qwen2.5:7b',
        ]);
    }

    public function test_it_classifies_http_and_invalid_json_outcomes_without_retaining_response_bodies(): void
    {
        Http::fakeSequence('ollama.test:11434/api/chat')
            ->push('PRIVATE_HTTP_ERROR_BODY', 503)
            ->push(['message' => ['content' => 'PRIVATE_INVALID_JSON_BODY']], 200);

        $client = app(OllamaClient::class);
        $httpError = $client->jsonWithTelemetry('private system prompt', 'private user query');
        $invalidJson = $client->jsonWithTelemetry('private system prompt', 'private user query');

        $this->assertSame('http_error', $httpError['outcome']);
        $this->assertSame(503, $httpError['http_status']);
        $this->assertNull($httpError['data']);
        $this->assertSame('invalid_json', $invalidJson['outcome']);
        $this->assertSame(200, $invalidJson['http_status']);
        $this->assertNull($invalidJson['data']);
        $this->assertStringNotContainsString('PRIVATE_', json_encode([$httpError, $invalidJson], JSON_THROW_ON_ERROR));
    }

    public function test_it_classifies_timeouts_and_transport_errors_without_logging_exception_text(): void
    {
        $outcomes = [
            new ConnectionException('cURL error 28: Operation timed out PRIVATE_TIMEOUT_MESSAGE'),
            new ConnectionException('Connection refused PRIVATE_TRANSPORT_MESSAGE'),
        ];
        $next = 0;
        Http::fake(static function () use (&$next, $outcomes): never {
            throw $outcomes[$next++];
        });

        $client = app(OllamaClient::class);
        $timeout = $client->jsonWithTelemetry('private system prompt', 'private user query');
        $transport = $client->jsonWithTelemetry('private system prompt', 'private user query');

        $this->assertSame('timeout', $timeout['outcome']);
        $this->assertSame('transport_error', $transport['outcome']);
        $this->assertStringNotContainsString('PRIVATE_', json_encode([$timeout, $transport], JSON_THROW_ON_ERROR));
    }

    public function test_it_reports_when_the_shared_search_budget_is_exhausted_before_sending(): void
    {
        config(["javmeta_search.budget_ms" => 1]);
        $budget = new SearchBudget();
        $this->app->instance(SearchBudget::class, $budget);
        Http::fake();

        $result = $budget->run(static function () {
            usleep(5_000);

            return app(OllamaClient::class)->jsonWithTelemetry('private system prompt', 'private user query');
        });

        $this->assertSame('budget_exhausted', $result['outcome']);
        $this->assertNull($result['http_status']);
        Http::assertNothingSent();
    }
}

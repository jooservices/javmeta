<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Embedding;

use App\Services\Embedding\EmbedderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EmbedderClientTest extends TestCase
{
    public function test_empty_input_returns_empty_vectors_without_requesting_embedder(): void
    {
        Http::fake();

        $this->assertSame([], (new EmbedderClient())->embedTexts([' ', "\t"]));
        Http::assertNothingSent();
    }

    public function test_successful_response_returns_float_vectors_and_query_model(): void
    {
        $text = fake()->sentence();
        $vector = [fake()->randomFloat(6, -1, 1), fake()->randomFloat(6, -1, 1)];

        Http::fake([
            '*' => Http::response(['data' => [['embedding' => $vector]]], 200),
        ]);

        $result = (new EmbedderClient())->embedTexts([$text, ' '], true);

        $this->assertSame([$vector], $result);
        Http::assertSent(function ($request) use ($text): bool {
            return $request->data()['model'] === 'javmeta-query'
                && $request->data()['input'] === [$text];
        });
    }

    public function test_passage_model_and_single_text_helper_return_one_vector(): void
    {
        $text = fake()->sentence();
        $vector = [fake()->randomFloat(6, -1, 1)];

        Http::fake([
            '*' => Http::response(['data' => [['embedding' => $vector]]], 200),
        ]);

        $this->assertSame($vector, (new EmbedderClient())->embedOne($text));
        Http::assertSent(function ($request): bool {
            return $request->data()['model'] === 'javmeta-passage';
        });
    }

    public function test_unsuccessful_or_malformed_response_fails_open(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([], 503)
                ->push(['data' => 'invalid'], 200)
                ->push(['data' => [['embedding' => 'invalid']]], 200),
        ]);

        $client = new EmbedderClient();

        $this->assertNull($client->embedTexts([fake()->sentence()]));
        $this->assertNull($client->embedTexts([fake()->sentence()]));
        $this->assertNull($client->embedTexts([fake()->sentence()]));
    }

    public function test_connection_failure_fails_open(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException(fake()->sentence());
        });

        $this->assertNull((new EmbedderClient())->embedTexts([fake()->sentence()]));
    }
}

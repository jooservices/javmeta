<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use App\Services\Search\QueryInterpreter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SearchParseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_ai.ollama_url' => 'http://ollama.test:11434', 'javmeta_ai.ollama_chat_model' => 'qwen2.5:7b']);
        app(SampleDataset::class)->load();
        Cache::flush();
    }

    public function test_parse_endpoint_returns_only_validated_filters_and_explanations(): void
    {
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);
        $key = app(ApiKeyService::class)->create('natural-language-parse', 60, ['movies'])->plaintext;

        $response = $this->postJson('/api/v1/search/parse', [
            'q' => 'Sample Maker 03 movies released in 2024',
        ], ['X-API-Key' => $key])->assertOk();

        $this->assertSame('Sample Maker 03', $response->json('data.filters.maker'));
        $this->assertSame('2024-01-01', $response->json('data.filters.released_from'));
        $this->assertSame('2024-12-31', $response->json('data.filters.released_to'));
        $this->assertSame('Maker: Sample Maker 03', $response->json('data.explanations.maker'));
        $this->assertSame('rules', $response->json('data.by'));
        Http::assertSent(static fn(\Illuminate\Http\Client\Request $request): bool => is_array($request['format']) && ($request['format']['type'] ?? null) === 'object' && ($request['format']['additionalProperties'] ?? true) === false);
    }

    public function test_rules_keep_unknown_words_and_extract_catalog_facets_and_ranges(): void
    {
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);

        $parsed = app(QueryInterpreter::class)->interpret(
            'Mio Sato 1 from Sample Maker 03 blood type O living in Tokyo age 25-35 cup D height 160-170 cm released in 2024 onsen scene',
        );

        $this->assertSame('rules', $parsed['by']);
        $this->assertSame('Sample Maker 03', $parsed['filters']['maker']);
        $this->assertSame('Mio Sato 1', $parsed['filters']['actress']);
        $this->assertSame('O', $parsed['filters']['blood_type']);
        $this->assertSame('Tokyo', $parsed['filters']['location']);
        $this->assertSame('D', $parsed['filters']['cup']);
        $this->assertSame(25, $parsed['filters']['age_min']);
        $this->assertSame(35, $parsed['filters']['age_max']);
        $this->assertSame(160, $parsed['filters']['height_min']);
        $this->assertSame(170, $parsed['filters']['height_max']);
        $this->assertSame('2024-01-01', $parsed['filters']['released_from']);
        $this->assertSame('2024-12-31', $parsed['filters']['released_to']);
        $this->assertStringContainsString('onsen scene', $parsed['keywords']);
        $before = app(QueryInterpreter::class)->interpret('movies before 2020');
        $this->assertSame('2019-12-31', $before['filters']['released_to']);
        $basedIn = app(QueryInterpreter::class)->interpret('cup C, height 155-165 cm, based in Osaka');
        $this->assertSame('Osaka', $basedIn['filters']['location']);
    }

    public function test_rules_match_capitalized_years_preserve_japanese_titles_and_ignore_embedded_age(): void
    {
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);

        $dates = [
            'Movies Released in 2024' => ['released_from' => '2024-01-01', 'released_to' => '2024-12-31'],
            'Since 2019' => ['released_from' => '2019-01-01'],
            'Before 2020' => ['released_to' => '2019-12-31'],
            'In 2024' => ['released_from' => '2024-01-01', 'released_to' => '2024-12-31'],
        ];
        foreach ($dates as $question => $expected) {
            $filters = app(QueryInterpreter::class)->interpret($question)['filters'];
            foreach ($expected as $field => $value) {
                $this->assertSame($value, $filters[$field] ?? null, $question);
            }
        }

        $this->assertArrayNotHasKey('age_min', app(QueryInterpreter::class)->interpret('image 2')['filters']);
        $this->assertStringContainsString('サンプル作品', app(QueryInterpreter::class)->interpret('サンプル作品を探して')['keywords']);

        $japaneseMovie = app(QueryInterpreter::class)->interpret('2024年の映画を探してください');
        $this->assertSame('2024-01-01', $japaneseMovie['filters']['released_from'] ?? null);
        $this->assertSame('探してください', $japaneseMovie['keywords']);
    }

    public function test_short_model_facets_require_standalone_ascii_mentions(): void
    {
        $cases = [
            ['A型の女優の作品を探してください', 'blood_type', 'A'],
            ['血液型AB型の女優の作品を探してください', 'blood_type', 'AB'],
            ['カップGの女優の作品を探してください', 'cup', 'G'],
        ];

        $sequence = Http::fakeSequence('ollama.test:11434/api/chat');
        foreach ($cases as [$question, $field, $value]) {
            $response = ['keywords' => ''];
            $response[$field] = $value;
            $sequence->push(['message' => ['content' => json_encode($response)]]);
        }
        $sequence->push(['message' => ['content' => json_encode(['keywords' => '', 'blood_type' => 'A', 'cup' => 'G'])]]);

        foreach ($cases as [$question, $field, $value]) {
            $parsed = app(QueryInterpreter::class)->interpret($question);
            $this->assertSame('ollama', $parsed['by'], $question);
            $this->assertSame($value, $parsed['filters'][$field] ?? null, $question);
        }

        $parsed = app(QueryInterpreter::class)->interpret('Find movies about office night');
        $this->assertArrayNotHasKey('blood_type', $parsed['filters']);
        $this->assertArrayNotHasKey('cup', $parsed['filters']);
    }

    public function test_parse_endpoint_validates_input_auth_and_scope(): void
    {
        $key = app(ApiKeyService::class)->create('natural-language-parse', 60, ['movies'])->plaintext;
        $this->postJson('/api/v1/search/parse', ['q' => '!!'], ['X-API-Key' => $key])->assertStatus(400);
        $this->postJson('/api/v1/search/parse', ['q' => 'a'], ['X-API-Key' => $key])->assertStatus(400);
        $this->postJson('/api/v1/search/parse', ['q' => 'valid question'], ['X-API-Key' => 'bad'])->assertUnauthorized();

        $statusOnly = app(ApiKeyService::class)->create('status-only', 60, ['status'])->plaintext;
        $this->postJson('/api/v1/search/parse', ['q' => 'valid question'], ['X-API-Key' => $statusOnly])->assertStatus(403);
    }

    public function test_the_same_question_is_read_from_cache(): void
    {
        Http::fake(['ollama.test:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['keywords' => 'office night'])]])]);
        $key = app(ApiKeyService::class)->create('natural-language-parse', 60, ['movies'])->plaintext;

        $this->postJson('/api/v1/search/parse', ['q' => 'movies about the office at night'], ['X-API-Key' => $key])->assertOk();
        $this->postJson('/api/v1/search/parse', ['q' => 'movies about the office at night'], ['X-API-Key' => $key])->assertOk();

        Http::assertSentCount(1);
    }

    public function test_model_values_not_in_the_question_or_catalog_are_dropped(): void
    {
        Http::fake(['ollama.test:11434/api/chat' => Http::response(['message' => ['content' => json_encode([
            'keywords' => 'unmentioned phrase',
            'maker' => 'Sample Maker 03',
            'genre' => 'Invented Genre',
        ])]])]);
        $key = app(ApiKeyService::class)->create('natural-language-parse', 60, ['movies'])->plaintext;

        $response = $this->postJson('/api/v1/search/parse', ['q' => 'find a movie with unknown words'], ['X-API-Key' => $key])->assertOk();

        $this->assertArrayNotHasKey('maker', $response->json('data.filters'));
        $this->assertArrayNotHasKey('genre', $response->json('data.filters'));
        $this->assertStringContainsString('unknown words', $response->json('data.keywords'));
    }
}

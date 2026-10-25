<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use App\Services\Search\QueryInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_ai.ollama_url' => 'http://ollama.test:11434', 'javmeta_ai.ollama_chat_model' => 'qwen2.5:7b']);
        app(SampleDataset::class)->load();
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_the_rules_read_years_runtimes_codes_and_catalog_names_without_any_model(): void
    {
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);
        $i = app(QueryInterpreter::class);

        $r = $i->interpret('Sample Maker 03 movies released in 2024 longer than 100 minutes');
        $this->assertSame('rules', $r['by']);
        $this->assertSame('Sample Maker 03', $r['filters']['maker']);
        $this->assertSame(['released_from' => '2024-01-01', 'released_to' => '2024-12-31'], array_intersect_key($r['filters'], array_flip(['released_from', 'released_to'])));
        $this->assertSame(100, $r['filters']['runtime_min']);
        $this->assertSame('', $r['keywords']);

        $this->assertSame('SMP-0007', $i->interpret('smp-0007 please')['filters']['code']);
        $this->assertSame(['released_from' => '2020-01-01', 'released_to' => '2023-12-31'], array_intersect_key($i->interpret('drama 2020-2023')['filters'], array_flip(['released_from', 'released_to'])));
        $this->assertSame('Sample Genre 05', $i->interpret('Sample Genre 05')['filters']['genre']);
        $this->assertSame('sunset beach', $i->interpret('sunset beach')['keywords']);
        $this->assertSame([], $i->interpret('sunset beach')['filters']);
    }

    public function test_ollama_reading_is_used_first_when_it_answers_and_bad_values_are_dropped(): void
    {
        Http::fake(['ollama.test:11434/api/chat' => Http::response(['message' => ['content' => json_encode(['keywords' => 'office night', 'maker' => 'Sample Maker 05', 'year_from' => 2023, 'year_to' => 99999, 'runtime_min' => -4, 'genre' => ['bad']])]])]);

        $r = app(QueryInterpreter::class)->interpret('movies from Sample Maker 05 after 2023 set in the office at night');

        $this->assertSame('ollama', $r['by']);
        $this->assertSame('office night', $r['keywords']);
        $this->assertSame('Sample Maker 05', $r['filters']['maker']);
        $this->assertSame('2023-01-01', $r['filters']['released_from']);
        $this->assertArrayNotHasKey('released_to', $r['filters']);
        $this->assertArrayNotHasKey('runtime_min', $r['filters']);
        $this->assertArrayNotHasKey('genre', $r['filters']);
    }

    public function test_the_search_endpoint_combines_filters_and_text_and_reports_how_it_understood(): void
    {
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('search')->plaintext];

        $this->getJson('/api/v1/search?q=x', $headers)->assertStatus(400);
        $this->getJson('/api/v1/search?q=abc', ['X-API-Key' => 'bad'])->assertUnauthorized();

        $r = $this->getJson('/api/v1/search?q=' . urlencode('Sample Maker 03') . '&per_page=50', $headers)->assertOk();
        $this->assertSame('Sample Maker 03', $r->json('data.interpretation.filters.maker'));
        $this->assertNotEmpty($r->json('data.movies'));
        foreach ($r->json('data.movies') as $movie) {
            $this->assertSame('Sample Maker 03', $movie['maker']);
        }

        $byCode = $this->getJson('/api/v1/search?q=SMP-0007', $headers)->assertOk();
        $this->assertSame('SMP-0007', $byCode->json('data.movies.0.code'));

        $performers = $this->getJson('/api/v1/search?q=' . urlencode('Aiko'), $headers)->assertOk();
        $this->assertNotEmpty($performers->json('data.performers'));
        $this->assertSame('none', $this->getJson('/api/v1/search?q=Aiko&understand=0', $headers)->json('data.interpretation.by'));
    }

    public function test_a_key_without_the_movies_scope_cannot_search(): void
    {
        $key = app(ApiKeyService::class)->create('no-movies', 60, ['status'])->plaintext;

        $this->getJson('/api/v1/search?q=abc', ['X-API-Key' => $key])->assertStatus(403);
    }

    public function test_an_over_specified_question_falls_back_to_the_filters_and_says_so(): void
    {
        Http::fake(['ollama.test:11434/*' => Http::response('down', 500)]);
        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('relax')->plaintext];

        $r = $this->getJson('/api/v1/search?q=' . urlencode('Sample Maker 03 zzqqxx') . '&per_page=20', $headers)->assertOk();

        $this->assertNotEmpty($r->json('data.movies'));
        $this->assertSame('filters only', $r->json('data.interpretation.relaxed'));
    }
}

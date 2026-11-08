<?php

declare(strict_types=1);

namespace Tests\Nl;

use App\Services\Dataset\SampleDataset;
use App\Services\Search\QueryInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class NaturalLanguageEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('NL_OLLAMA_URL');
        if (! is_string($url) || trim($url) === '') {
            $this->markTestSkipped('Set NL_OLLAMA_URL to an authorized local Ollama endpoint to run the opt-in evaluation.');
        }

        config([
            'javmeta_ai.ollama_url' => rtrim($url, '/'),
            'javmeta_ai.ollama_chat_model' => getenv('NL_OLLAMA_CHAT_MODEL') ?: 'qwen2.5:7b',
            'javmeta_ai.nl_timeout_seconds' => 8,
            'javmeta_ai.nl_enabled' => true,
        ]);
        app(SampleDataset::class)->load();
        Cache::flush();
    }

    public function test_ollama_meets_the_documented_filter_accuracy_threshold(): void
    {
        $examples = json_decode((string) file_get_contents(base_path('tests/Fixtures/nl-query-examples.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(40, $examples);
        $languages = array_values(array_unique(array_column($examples, 'language')));
        sort($languages);
        $this->assertSame(['en', 'ja', 'vi'], $languages);

        $matched = 0;
        $expectedCount = 0;
        $ollamaCount = 0;
        $misses = [];

        foreach ($examples as $example) {
            $actual = app(QueryInterpreter::class)->interpret($example['q']);
            $ollamaCount += $actual['by'] === 'ollama' ? 1 : 0;
            foreach ($example['expected'] as $field => $expected) {
                $expectedCount++;
                $value = $actual['filters'][$field] ?? null;
                if ($this->same($expected, $value)) {
                    $matched++;
                    continue;
                }
                $misses[] = sprintf('%s: expected %s=%s, got %s', $example['q'], $field, json_encode($expected), json_encode($value));
            }
        }

        $accuracy = $expectedCount === 0 ? 0.0 : $matched / $expectedCount;
        fwrite(STDOUT, sprintf("\nNatural-language evaluation: %d/%d expected filter values (%.1f%%) across %d questions (%d parsed with Ollama).\n", $matched, $expectedCount, $accuracy * 100, count($examples), $ollamaCount));
        foreach ($misses as $miss) {
            fwrite(STDOUT, $miss . "\n");
        }

        $this->assertGreaterThan(0, $ollamaCount, 'The evaluation must include at least one answer from Ollama.');
        $this->assertGreaterThanOrEqual(0.80, $accuracy, 'The local Ollama parser must match at least 80% of expected filter values.');
    }

    private function same(mixed $expected, mixed $actual): bool
    {
        if (is_int($expected) || is_float($expected)) {
            return is_numeric($actual) && (int) $expected === (int) $actual;
        }

        return is_string($expected) && is_string($actual) && mb_strtolower($expected) === mb_strtolower($actual);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Embedding\EmbedderClient;
use App\Services\Embedding\OllamaClient;
use App\Services\Search\SearchBudget;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SearchBudgetTest extends TestCase
{
    public function test_deadline_is_shared_and_restored_after_an_exception(): void
    {
        config(['javmeta_search.budget_ms' => 25]);
        $now = 1_000_000_000;
        $budget = new SearchBudget(static function () use (&$now): int {
            return $now;
        });
        $this->assertSame(15.0, $budget->seconds(15));
        try {
            $budget->run(function () use ($budget, &$now): void {
                $this->assertSame(.025, $budget->seconds(15));
                $now += 5_000_000;
                $this->assertSame(.020, $budget->seconds(15));
                throw new RuntimeException('test');
            });
        } catch (RuntimeException) {
            $this->assertSame(15.0, $budget->seconds(15));
        }
    }

    public function test_expired_budget_does_not_start_embedding_or_interpretation_http(): void
    {
        config(['javmeta_search.budget_ms' => 1]);
        Http::fake();
        $now = 1_000_000_000;
        $budget = new SearchBudget(static function () use (&$now): int {
            return $now;
        });
        $this->app->instance(SearchBudget::class, $budget);
        $budget->run(function () use (&$now): void {
            $now += 1_000_000;
            $this->assertTrue(app(SearchBudget::class)->expired());
            $this->assertNull(app(EmbedderClient::class)->embedOne('test'));
            $this->assertNull(app(OllamaClient::class)->embed(['test']));
            $this->assertNull(app(OllamaClient::class)->json('system', 'question'));
        });
        Http::assertNothingSent();
    }
}

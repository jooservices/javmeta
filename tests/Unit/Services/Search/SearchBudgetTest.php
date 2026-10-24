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
        $budget = app(SearchBudget::class);
        $this->assertSame(15.0, $budget->seconds(15));
        try {
            $budget->run(function () use ($budget): void {
                $this->assertGreaterThan(0, $budget->seconds(15));
                $this->assertLessThanOrEqual(.025, $budget->seconds(15));
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
        app(SearchBudget::class)->run(function (): void {
            usleep(3000);
            $this->assertTrue(app(SearchBudget::class)->expired());
            $this->assertNull(app(EmbedderClient::class)->embedOne('test'));
            $this->assertNull(app(OllamaClient::class)->embed(['test']));
            $this->assertNull(app(OllamaClient::class)->json('system', 'question'));
        });
        Http::assertNothingSent();
    }
}

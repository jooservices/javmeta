<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The parts of the discovery area that never reach the search tables (those exist only on PostgreSQL):
 * validation, problem codes, and that every route is public. The data itself is covered by tests/Integration/Api/V2/Discovery*.
 */
final class DiscoveryValidationTest extends V2TestCase
{
    use RefreshDatabase;

    public function test_a_search_without_text_is_422_with_the_field_for_a_guest_and_a_signed_in_user(): void
    {
        $this->getJson('/api/v2/search')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.0.field', 'q');
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v2/search?q=%20%20')->assertStatus(422)->assertJsonPath('errors.0.field', 'q');
    }

    public function test_the_interpretation_is_validated_before_anything_is_read_and_needs_no_csrf_token(): void
    {
        $this->postJson('/api/v2/search/interpretations', [])->assertStatus(422)->assertJsonPath('errors.0.field', 'q');
        $this->postJson('/api/v2/search/interpretations', ['q' => 'a', 'target' => 'albums'])->assertStatus(422)->assertJsonPath('errors.0.field', 'target');
        $this->postJson('/api/v2/search/interpretations', ['q' => 'a', 'ignore' => 'x'])->assertStatus(422)->assertJsonPath('errors.0.field', 'ignore');
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/v2/search/interpretations', ['q' => str_repeat('a', 301)])->assertStatus(422)->assertJsonPath('errors.0.field', 'q');
    }

    public function test_short_suggestions_and_catalogue_texts_answer_empty_without_signing_in(): void
    {
        $this->getJson('/api/v2/suggestions?q=a')->assertOk()->assertJsonPath('data.movies', []);
        $this->getJson('/api/v2/search/catalog?q=a')->assertOk()->assertJsonPath('data', []);
    }

    public function test_calendar_parameters_are_checked(): void
    {
        $this->getJson('/api/v2/calendar?month=March')->assertStatus(422)->assertJsonPath('errors.0.field', 'month');
        $this->getJson('/api/v2/calendar?day=2025-02-31')->assertStatus(422)->assertJsonPath('errors.0.field', 'day');
    }

    public function test_unknown_choices_are_400_with_their_own_problem_code(): void
    {
        $this->getJson('/api/v2/updates?type=episodes')->assertStatus(400)->assertJsonPath('code', 'invalid_type');
        $this->getJson('/api/v2/statistics/distributions?by=colour')->assertStatus(400)->assertJsonPath('code', 'invalid_distribution');
        $this->getJson('/api/v2/statistics/correlations?pair=a-b')->assertStatus(400)->assertJsonPath('code', 'invalid_pair');
        $this->getJson('/api/v2/statistics/compare?kind=makers&names=one')->assertStatus(400)->assertJsonPath('code', 'invalid_compare');
        $this->getJson('/api/v2/statistics/compare?kind=colours&names=a,b')->assertStatus(400)->assertJsonPath('code', 'invalid_compare');
    }

    public function test_the_problem_answer_carries_the_request_id_as_trace(): void
    {
        $response = $this->getJson('/api/v2/updates?type=nope', ['X-Request-Id' => 'abc-123'])->assertStatus(400);

        $response->assertJsonPath('type', '/problems/invalid_type')->assertJsonPath('trace_id', 'abc-123')->assertHeader('Content-Type', 'application/problem+json');
    }
}

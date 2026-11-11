<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UiAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_the_owner_session(): void
    {
        $this->get('/ui-admin/keys')->assertRedirect();
        $this->postJson('/ui-admin/keys', ['label' => 'x'])->assertStatus(401);
    }

    public function test_the_owner_creates_lists_checks_and_revokes_a_key(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner);

        $created = $this->postJson('/ui-admin/keys', ['label' => 'ui-made', 'abuse_rpm' => 30, 'scopes' => ['catalog', 'status']])->assertCreated();
        $id = $created->json('data.id');
        $this->assertStringStartsWith('jvm_', $created->json('data.plaintext'));
        $this->assertSame(['catalog', 'status'], ApiKey::query()->find($id)->scopes);

        $this->getJson('/ui-admin/keys')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'ui-made')
            ->assertJsonPath('meta.pagination.per_page', 25);
        $this->getJson('/ui-admin/keys/' . $id . '/usage')->assertOk()->assertJsonPath('data.last_24h.calls', 0);
        $this->getJson('/ui-admin/report/quality')->assertOk()->assertJsonStructure(['data' => ['movies', 'core_fields']]);
        $this->deleteJson('/ui-admin/keys/' . $id)->assertNoContent();
        $this->assertSame('revoked', ApiKey::query()->find($id)->status);
        $this->postJson('/ui-admin/keys', ['label' => 'bad', 'scopes' => ['nope']])->assertStatus(422);
        $missing = $this->postJson('/ui-admin/keys', ['label' => 'no-scope'])->assertStatus(422);
        $this->assertSame(['Chọn ít nhất một quyền.'], $missing->json('errors.scopes'));
        $this->postJson('/ui-admin/keys', ['label' => 'empty-scope', 'scopes' => []])->assertStatus(422);
    }

    public function test_the_owner_can_keyset_page_active_api_keys_without_an_unbounded_count(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $older = ApiKey::factory()->create(['label' => 'older-active']);
        ApiKey::factory()->create([
            'label' => 'revoked',
            'status' => ApiKey::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
        $middle = ApiKey::factory()->create(['label' => 'middle-active']);
        $newer = ApiKey::factory()->create(['label' => 'newer-active']);
        $this->actingAs($owner);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $firstPage = $this->getJson('/ui-admin/keys?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->getKey())
            ->assertJsonPath('data.0.label', 'newer-active')
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.has_more', true)
            ->assertJsonMissingPath('data.0.key_hash')
            ->assertJsonMissingPath('data.0.plaintext');

        $this->assertNotEmpty($firstPage->json('meta.pagination.next_cursor'));
        $this->assertFalse(collect($queries)->contains(
            static fn(string $sql): bool => str_contains($sql, 'count(') && str_contains($sql, 'api_keys'),
        ), 'The active-key listing must not run an exact count.');
        $this->assertTrue(collect($queries)->contains(
            static fn(string $sql): bool => str_contains($sql, 'api_keys')
                && str_contains($sql, 'status')
                && str_contains($sql, 'limit 2')
                && ! str_contains($sql, ' offset '),
        ), 'The active-key page query must use a bounded keyset read.');

        $this->getJson('/ui-admin/keys?cursor=' . $firstPage->json('meta.pagination.next_cursor') . '&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $middle->getKey())
            ->assertJsonPath('meta.pagination.has_more', true);
        $this->getJson('/ui-admin/keys?cursor=' . $middle->getKey() . '&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $older->getKey())
            ->assertJsonPath('meta.pagination.has_more', false);
    }

    public function test_the_owner_gets_an_empty_active_page_and_invalid_page_sizes_are_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        ApiKey::factory()->create([
            'status' => ApiKey::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
        $this->actingAs($owner);

        $this->getJson('/ui-admin/keys')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.has_more', false)
            ->assertJsonPath('meta.pagination.next_cursor', null);

        $this->getJson('/ui-admin/keys?cursor=invalid')->assertUnprocessable();
        $this->getJson('/ui-admin/keys?per_page=101')->assertUnprocessable();
    }

    public function test_active_key_listing_has_the_filter_and_sort_index(): void
    {
        $indexes = Schema::getIndexes('api_keys');
        $this->assertTrue(collect($indexes)->contains(
            static fn(array $index): bool => $index['name'] === 'api_keys_status_id_index'
                && $index['columns'] === ['status', 'id'],
        ));
    }

    public function test_the_owner_can_create_a_key_limited_to_browser_service_configuration(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner);

        $created = $this->postJson('/ui-admin/keys', ['label' => 'browser-config', 'scopes' => ['config']])->assertCreated();
        $this->assertSame(['config'], ApiKey::query()->find($created->json('data.id'))->scopes);
    }

    public function test_unknown_report_type_returns_a_safe_not_found_message(): void
    {
        $owner = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner);

        $this->getJson('/ui-admin/report/legacy')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Không tìm thấy báo cáo yêu cầu.']);
    }
}

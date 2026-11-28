<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\ApiKey;
use App\Services\Api\V2\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdministrationApiKeysTest extends AdministrationTestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function operations(): array
    {
        return [
            'list keys' => ['GET', '/api/v2/api-keys'],
            'create key' => ['POST', '/api/v2/api-keys'],
            'revoke key' => ['DELETE', '/api/v2/api-keys/1'],
            'key usage' => ['GET', '/api/v2/api-keys/1/usage'],
            'all settings' => ['GET', '/api/v2/settings'],
            'one settings group' => ['GET', '/api/v2/settings/report'],
            'change settings group' => ['PUT', '/api/v2/settings/report'],
            'list templates' => ['GET', '/api/v2/notification-templates'],
            'one template' => ['GET', '/api/v2/notification-templates/backup.stale'],
            'change template' => ['PUT', '/api/v2/notification-templates/backup.stale'],
            'template revisions' => ['GET', '/api/v2/notification-templates/backup.stale/revisions'],
            'restore revision' => ['POST', '/api/v2/notification-templates/backup.stale/revisions/1/restorations'],
            'preview template' => ['POST', '/api/v2/notification-templates/backup.stale/previews'],
            'test delivery' => ['POST', '/api/v2/notification-templates/backup.stale/test-deliveries'],
            'reset template' => ['DELETE', '/api/v2/notification-templates/backup.stale/overrides'],
            'report schedule' => ['GET', '/api/v2/reports/schedule'],
            'change schedule' => ['PUT', '/api/v2/reports/schedule'],
            'next reports' => ['GET', '/api/v2/reports/previews'],
            'report history' => ['GET', '/api/v2/reports/deliveries'],
            'send report' => ['POST', '/api/v2/reports/deliveries'],
            'data report' => ['GET', '/api/v2/reports/quality'],
        ];
    }

    #[DataProvider('operations')]
    public function test_a_guest_gets_401_and_a_plain_user_gets_403(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'authentication_required');

        $this->actingAs($this->user('user'), 'web')->getJson('/api/v2/sessions/current');
        $csrf = ['X-CSRF-Token' => (string) $this->app['session.store']->token()];
        $this->json($method, $uri, [], $csrf)->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_an_unsafe_request_of_an_admin_without_the_csrf_token_is_403(): void
    {
        $this->actingAs($this->user('super_administrator'), 'web')->postJson('/api/v2/api-keys', ['label' => 'x'])->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');
    }

    public function test_a_key_is_created_listed_with_a_filter_and_the_secret_is_shown_only_once(): void
    {
        [, $csrf] = $this->admin();

        $created = $this->postJson('/api/v2/api-keys', ['label' => 'Deploy', 'abuse_rpm' => 30], $csrf)->assertCreated();
        $id = (int) $created->json('data.id');
        $created->assertHeader('Location', "/api/v2/api-keys/{$id}")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.abuse_rpm', 30)
            ->assertJsonPath('data.scopes', ['movies', 'performers', 'catalog', 'status']);
        $secret = (string) $created->json('data.secret');
        $this->assertStringStartsWith('jvm_', $secret);
        $this->assertSame(substr($secret, 0, 12), $created->json('data.prefix'));
        $this->assertSame(hash('sha256', $secret), DB::table('api_keys')->where('id', $id)->value('key_hash'));

        $list = $this->getJson('/api/v2/api-keys?status=active')->assertOk();
        $list->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.pagination.total', 1)->assertJsonMissingPath('data.0.secret');
        $this->getJson('/api/v2/api-keys?status=revoked')->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_creating_a_key_validates_the_body(): void
    {
        [, $csrf] = $this->admin();

        $this->postJson('/api/v2/api-keys', [], $csrf)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonFragment(['field' => 'label', 'code' => 'required']);
        $this->postJson('/api/v2/api-keys', ['label' => 'x', 'scopes' => ['nope']], $csrf)->assertStatus(422)->assertJsonFragment(['field' => 'scopes.0', 'code' => 'in']);
        $this->getJson('/api/v2/api-keys?per_page=500&status=other')->assertStatus(422)->assertJsonFragment(['field' => 'per_page', 'code' => 'max'])->assertJsonFragment(['field' => 'status', 'code' => 'in']);
    }

    public function test_revoking_is_idempotent_and_clears_nothing_else(): void
    {
        [, $csrf] = $this->admin();
        $id = (int) $this->postJson('/api/v2/api-keys', ['label' => 'Gone', 'scopes' => ['movies', 'movies']], $csrf)->json('data.id');

        $this->deleteJson("/api/v2/api-keys/{$id}", [], $csrf)->assertNoContent();
        $this->deleteJson("/api/v2/api-keys/{$id}", [], $csrf)->assertNoContent();
        $this->deleteJson('/api/v2/api-keys/99999', [], $csrf)->assertNoContent();

        $row = $this->getJson('/api/v2/api-keys?status=revoked')->assertOk();
        $row->assertJsonPath('data.0.status', 'revoked')->assertJsonPath('data.0.scopes', ['movies']);
        $this->assertNotNull($row->json('data.0.revoked_at'));
        $this->assertSame(ApiKey::STATUS_REVOKED, DB::table('api_keys')->where('id', $id)->value('status'));
    }

    public function test_usage_counts_the_calls_of_the_last_day_and_404s_for_an_unknown_key(): void
    {
        [, $csrf] = $this->admin();
        $id = (int) $this->postJson('/api/v2/api-keys', ['label' => 'Busy'], $csrf)->json('data.id');
        $rows = [
            ['endpoint' => '/v1/movies', 'status_code' => 200, 'created_at' => now()->subSeconds(10)],
            ['endpoint' => '/v1/movies', 'status_code' => 500, 'created_at' => now()->subHours(2)],
            ['endpoint' => '/v1/performers', 'status_code' => 200, 'created_at' => now()->subHours(3)],
            ['endpoint' => '/v1/old', 'status_code' => 200, 'created_at' => now()->subDays(3)],
        ];
        foreach ($rows as $row) {
            DB::table('api_usage_log')->insert($row + ['api_key_id' => $id, 'method' => 'GET']);
        }

        $this->getJson("/api/v2/api-keys/{$id}/usage")->assertOk()
            ->assertJsonPath('data.calls_24h', 3)
            ->assertJsonPath('data.errors_24h', 1)
            ->assertJsonPath('data.rpm', 1)
            ->assertJsonPath('data.top_endpoints.0', ['endpoint' => '/v1/movies', 'n' => 2]);
        $this->assertNotNull($this->getJson("/api/v2/api-keys/{$id}/usage")->json('data.last_used'));

        $this->getJson('/api/v2/api-keys/99999/usage')->assertNotFound()->assertJsonPath('code', 'api_key_not_found');
    }

    public function test_a_key_without_usage_has_no_last_use_and_the_changes_are_audited(): void
    {
        [$admin, $csrf] = $this->admin();
        $id = (int) $this->postJson('/api/v2/api-keys', ['label' => 'Quiet'], $csrf)->json('data.id');
        $this->deleteJson("/api/v2/api-keys/{$id}", [], $csrf)->assertNoContent();

        $this->getJson("/api/v2/api-keys/{$id}/usage")->assertOk()->assertJsonPath('data.last_used', null)->assertJsonPath('data.calls_24h', 0);
        $actions = DB::table('audit_log')->where('actor', $admin->email)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['api_key.create', 'api_key.revoke'], $actions);
    }

    public function test_the_audit_log_binding_records_a_row(): void
    {
        $this->app->make(AuditLog::class)->record('someone', 'thing.done', 'things/1', ['a' => 1]);

        $row = DB::table('audit_log')->first();
        $this->assertSame('someone', $row->actor);
        $this->assertSame('thing.done', $row->action);
        $this->assertSame('things/1', $row->target);
        $this->assertSame(['a' => 1], json_decode((string) $row->details, true));
    }
}

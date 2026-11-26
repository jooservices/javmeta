<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Contracts\ReportChannel;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;

/** The v2 administration area against real PostgreSQL: keys and usage, settings, templates, schedule, reports, audit. */
final class AdministrationTest extends PostgresApiTestCase
{
    /** @var array<string, string> */
    private array $csrf = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        SettingsService::forgetDefaults();
        $this->app->instance(ReportChannel::class, new class implements ReportChannel {
            public function deliver(string $subject, string $body, array $context = []): bool
            {
                return true;
            }
        });

        $this->actingAs(User::factory()->create(['role' => 'super_administrator']), 'web')->getJson('/api/v2/sessions/current');
        $this->csrf = ['X-CSRF-Token' => (string) $this->app['session.store']->token()];
    }

    public function test_a_key_is_created_used_listed_and_revoked(): void
    {
        $created = $this->postJson('/api/v2/api-keys', ['label' => 'Real', 'scopes' => ['movies', 'media']], $this->csrf)->assertCreated();
        $id = (int) $created->json('data.id');
        DB::table('api_usage_log')->insert([
            ['api_key_id' => $id, 'endpoint' => '/v1/movies', 'method' => 'GET', 'status_code' => 200, 'created_at' => now()],
            ['api_key_id' => $id, 'endpoint' => '/v1/movies', 'method' => 'GET', 'status_code' => 429, 'created_at' => now()->subHour()],
            ['api_key_id' => $id, 'endpoint' => '/v1/old', 'method' => 'GET', 'status_code' => 200, 'created_at' => now()->subDays(2)],
        ]);

        $usage = $this->getJson("/api/v2/api-keys/{$id}/usage")->assertOk();
        $usage->assertJsonPath('data.calls_24h', 2)->assertJsonPath('data.errors_24h', 1)->assertJsonPath('data.rpm', 1)->assertJsonPath('data.top_endpoints.0.endpoint', '/v1/movies');
        self::assertNotNull($usage->json('data.last_used'));

        $listed = $this->getJson('/api/v2/api-keys?status=active')->assertOk();
        $listed->assertJsonPath('data.0.scopes', ['movies', 'media'])->assertJsonPath('data.0.prefix', $created->json('data.prefix'));
        $this->deleteJson("/api/v2/api-keys/{$id}", [], $this->csrf)->assertNoContent();
        $this->getJson('/api/v2/api-keys?status=revoked')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_settings_are_saved_with_etag_and_a_revision_in_the_database(): void
    {
        $group = $this->getJson('/api/v2/settings/report')->assertOk();

        $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => 21]], $this->csrf + ['If-Match' => (string) $group->headers->get('ETag')])->assertOk();

        self::assertSame(2, (int) DB::table('setting_revisions')->where('key', 'report.history_days')->value('revision'));
        self::assertSame(21, app(SettingsService::class)->get('report.history_days'));
        $this->putJson('/api/v2/settings/report', ['values' => ['report.history_days' => 22]], $this->csrf + ['If-Match' => (string) $group->headers->get('ETag')])->assertStatus(412);
    }

    public function test_a_template_is_edited_restored_and_reset_with_history_kept(): void
    {
        $url = '/api/v2/notification-templates/backup.stale';
        $etag = (string) $this->getJson($url)->headers->get('ETag');
        $this->putJson($url, ['title_template' => 'New {level}', 'cooldown_minutes' => 99], $this->csrf + ['If-Match' => $etag])->assertOk()->assertJsonPath('data.version', 2);
        $revision = (int) $this->getJson("{$url}/revisions")->json('data.0.id');

        $this->postJson("{$url}/revisions/{$revision}/restorations", [], $this->csrf)->assertCreated()->assertJsonPath('data.version', 3);
        $this->deleteJson("{$url}/overrides", [], $this->csrf)->assertNoContent();
        $this->postJson("{$url}/previews", ['variables' => ['level' => 'X']], $this->csrf)->assertOk()->assertJsonPath('data.variables.level', 'X');
        $this->postJson("{$url}/test-deliveries", [], $this->csrf)->assertStatus(202);

        self::assertGreaterThanOrEqual(2, count($this->getJson("{$url}/revisions")->json('data')));
        self::assertSame(1, DB::table('audit_log')->where('action', 'notification_template.update')->count());
    }

    public function test_the_schedule_is_saved_and_the_history_and_reports_are_read(): void
    {
        $etag = (string) $this->getJson('/api/v2/reports/schedule')->headers->get('ETag');
        $body = [
            'periodic' => ['enabled' => true, 'every_minutes' => 120, 'include' => ['hosts']],
            'daily' => ['enabled' => false, 'time' => '09:30', 'timezone' => 'Asia/Ho_Chi_Minh', 'include' => ['workers']],
            'quiet_mode' => ['enabled' => true, 'from' => '22:00', 'to' => '06:00'],
        ];
        $this->putJson('/api/v2/reports/schedule', $body, $this->csrf + ['If-Match' => $etag])->assertOk()->assertJsonPath('data.daily.time', '09:30');
        $this->getJson('/api/v2/reports/previews')->assertOk()->assertJsonPath('data.daily_next', '09:30 Asia/Ho_Chi_Minh');

        $sent = $this->postJson('/api/v2/reports/deliveries', [], $this->csrf)->assertStatus(202)->assertJsonPath('data.result', 'sent');
        $this->getJson('/api/v2/reports/deliveries')->assertOk()->assertJsonPath('data.0.id', $sent->json('data.id'))->assertJsonPath('meta.pagination.total', 1);
        foreach (['quality', 'cost', 'sources'] as $type) {
            $this->getJson("/api/v2/reports/{$type}")->assertOk();
        }
    }
}

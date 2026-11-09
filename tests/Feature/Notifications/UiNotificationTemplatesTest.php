<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\ReportChannel;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class UiNotificationTemplatesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{title: string, body: string, context: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The framework test client sends Accept-Language en-us; these assertions read the Vietnamese catalog.
        $this->withHeader('Accept-Language', 'vi');
        $this->app->instance(ReportChannel::class, new class ($this) implements ReportChannel {
            public function __construct(private readonly UiNotificationTemplatesTest $test) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->test->record($subject, $body, $context);

                return true;
            }
        });
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
    }

    /** @param array<string, mixed> $context */
    public function record(string $title, string $body, array $context): void
    {
        $this->sent[] = ['title' => $title, 'body' => $body, 'context' => $context];
    }

    public function test_owner_can_list_and_read_notification_templates(): void
    {
        $this->getJson('/ui-admin/notifications')->assertOk()
            ->assertJsonPath('data.0.key', 'crawl.success_rate_low')
            ->assertJsonPath('data.0.name', 'Tỷ lệ crawl thành công thấp')
            ->assertJsonPath('data.0.usage', 'Gửi khi tỷ lệ thành công xuống dưới ngưỡng.');
        $this->getJson('/ui-admin/notifications/crawl.dispatch_delayed')
            ->assertOk()
            ->assertJsonPath('data.default_severity', 'warning')
            ->assertJsonPath('data.tier', 'warning')
            ->assertJsonPath('data.cooldown_minutes', 120)
            ->assertJsonPath('data.escalation_minutes', 60)
            ->assertJsonPath('data.enabled', true);
    }

    public function test_owner_can_edit_preview_test_send_reset_and_restore_a_revision(): void
    {
        $key = 'crawl.dispatch_delayed';
        $initial = $this->getJson('/ui-admin/notifications/' . $key)->assertOk()->json('data');
        $this->putJson('/ui-admin/notifications/' . $key, [
            'title_template' => '🔴 [XCrawler][CRITICAL] Dispatch delayed {dispatch_age}',
            'body_template' => 'Due work: {due_items}',
            'enabled' => true,
            'default_severity' => 'critical',
            'tier' => 'critical',
            'cooldown_minutes' => 240,
            'escalation_minutes' => 0,
            'include_in_periodic_report' => false,
            'include_in_daily_report' => true,
            'send_resolved' => true,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/ui-admin/notifications/' . $key, [
            'title_template' => 'Second edit {dispatch_age}',
            'body_template' => 'Second body {due_items}',
            'enabled' => false,
            'default_severity' => 'warning',
        ])->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson('/ui-admin/notifications/' . $key)->assertJsonPath('data.tier', 'critical')
            ->assertJsonPath('data.cooldown_minutes', 240)
            ->assertJsonPath('data.escalation_minutes', null)
            ->assertJsonPath('data.include_in_daily_report', true);

        $this->postJson('/ui-admin/notifications/' . $key . '/preview', [
            'title_template' => 'Alert {level_marker} {level} {dispatch_age}',
            'body_template' => 'Due {due_items} <b>unsafe</b>',
            'default_severity' => 'warning',
        ])->assertOk()->assertJsonPath('data.title', 'Alert 🟠 WARNING 190 seconds ago')
            ->assertJsonPath('data.body', 'Due 96,740 <b>unsafe</b>');

        $this->postJson('/ui-admin/notifications/' . $key . '/test', [
            'title_template' => 'Test {level_marker} {level} {dispatch_age}',
            'body_template' => 'Due {due_items}',
            'default_severity' => 'notice',
        ])->assertOk()->assertJsonPath('data.sent', true);
        self::assertSame(1, count($this->sent));
        self::assertSame('crawl.dispatch_delayed', $this->sent[0]['context']['template_key']);
        self::assertTrue($this->sent[0]['context']['test_send']);
        self::assertSame('Test 🟡 NOTICE 190 seconds ago', $this->sent[0]['title']);
        $this->getJson('/ui-admin/notifications/' . $key)->assertOk()->assertJsonPath('data.sent_count', 1);

        $revision = $this->getJson('/ui-admin/notifications/' . $key . '/revisions')->assertOk()->json('data.0.id');
        self::assertNotNull($revision);
        $this->postJson('/ui-admin/notifications/' . $key . '/reset')->assertOk()
            ->assertJsonPath('data.title_template', $initial['title_template'])
            ->assertJsonPath('data.tier', $initial['tier'])
            ->assertJsonPath('data.cooldown_minutes', $initial['cooldown_minutes']);
        $this->postJson('/ui-admin/notifications/' . $key . '/revisions/' . $revision . '/restore')->assertOk()
            ->assertJsonPath('data.title_template', '🔴 [XCrawler][CRITICAL] Dispatch delayed {dispatch_age}');
    }

    public function test_invalid_and_unknown_templates_are_rejected(): void
    {
        $this->putJson('/ui-admin/notifications/crawl.dispatch_delayed', [
            'title_template' => 'Alert {unknown}',
            'body_template' => 'Body',
            'enabled' => true,
            'default_severity' => 'critical',
        ])->assertUnprocessable()->assertJsonPath('message', 'Unknown template variable: {unknown}.');

        $this->getJson('/ui-admin/notifications/unknown.template')->assertNotFound();
    }

    public function test_unknown_keys_are_not_found_on_every_read_and_action_route(): void
    {
        $unknown = '/ui-admin/notifications/unknown.template';

        $this->postJson($unknown . '/preview')->assertNotFound();
        $this->postJson($unknown . '/test')->assertNotFound();
        $this->getJson($unknown . '/revisions')->assertNotFound();
        $this->postJson($unknown . '/reset')->assertNotFound();
    }

    public function test_a_template_with_an_unknown_variable_cannot_be_previewed_or_test_sent(): void
    {
        $bad = ['title_template' => 'Alert {unknown}', 'body_template' => 'Body'];

        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/preview', $bad)
            ->assertUnprocessable()->assertJsonPath('message', 'Unknown template variable: {unknown}.');
        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/test', $bad)
            ->assertUnprocessable()->assertJsonPath('message', 'Unknown template variable: {unknown}.');
        self::assertSame([], $this->sent);
    }

    public function test_preview_and_test_send_validate_their_fields(): void
    {
        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/preview', ['default_severity' => 'loud'])->assertUnprocessable();
        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/test', ['title_template' => str_repeat('x', 10001)])->assertUnprocessable();
    }

    public function test_a_failed_delivery_is_reported_as_unavailable_and_not_marked_sent(): void
    {
        $this->app->instance(ReportChannel::class, new class implements ReportChannel {
            public function deliver(string $subject, string $body, array $context = []): bool
            {
                return false;
            }
        });

        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/test')
            ->assertStatus(503)->assertJsonPath('data.sent', false);
    }

    public function test_policy_fields_must_be_sent_together_and_an_unknown_revision_cannot_be_restored(): void
    {
        $this->putJson('/ui-admin/notifications/crawl.dispatch_delayed', [
            'title_template' => 'Dispatch delayed {dispatch_age}',
            'body_template' => 'Due work: {due_items}',
            'enabled' => true,
            'default_severity' => 'warning',
            'tier' => 'warning',
        ])->assertUnprocessable()->assertJsonPath('message', 'All notification policy fields must be provided together.');

        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/revisions/999999/restore')->assertUnprocessable();
    }

    public function test_anonymous_owner_only_template_routes_require_login(): void
    {
        auth()->logout();
        $this->getJson('/ui-admin/notifications')->assertUnauthorized();
        $this->postJson('/ui-admin/notifications/crawl.dispatch_delayed/preview')->assertUnauthorized();
    }

    public function test_owner_can_set_quiet_mode_and_preview_daily_report(): void
    {
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $settings = $this->getJson('/ui-admin/settings')->assertOk();
        self::assertSame(false, collect($settings->json('data.settings'))->firstWhere('key', 'notifications.quiet_mode')['value']);

        $this->putJson('/ui-admin/settings', ['settings' => ['notifications.quiet_mode' => true]])->assertOk();
        $savedSettings = $this->getJson('/ui-admin/settings')->assertOk();
        self::assertSame(true, collect($savedSettings->json('data.settings'))->firstWhere('key', 'notifications.quiet_mode')['value']);
        $this->getJson('/ui-admin/report/daily')->assertOk()
            ->assertJsonPath('data.data.window_minutes', 1440)
            ->assertJsonPath('data.data.report_type', 'daily');
    }

    public function test_daily_report_time_and_timezone_are_editable_and_validated_in_settings(): void
    {
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);

        $this->putJson('/ui-admin/settings', ['settings' => [
            'report.daily_time' => '07:30',
            'report.daily_timezone' => 'UTC',
        ]])->assertOk()->assertJsonPath('data.applied', ['report.daily_time', 'report.daily_timezone']);

        $settings = $this->getJson('/ui-admin/settings')->assertOk()->json('data.settings');
        self::assertSame('07:30', collect($settings)->firstWhere('key', 'report.daily_time')['value']);
        self::assertSame('UTC', collect($settings)->firstWhere('key', 'report.daily_timezone')['value']);

        $this->putJson('/ui-admin/settings', ['settings' => ['report.daily_time' => '25:90']])
            ->assertUnprocessable()
            ->assertJsonPath('data.errors', ['report.daily_time' => 'report.daily_time: must use HH:MM']);
        $this->putJson('/ui-admin/settings', ['settings' => ['report.daily_timezone' => 'Invalid/Timezone']])
            ->assertUnprocessable()
            ->assertJsonPath('data.errors', ['report.daily_timezone' => 'report.daily_timezone: must be a valid IANA timezone']);
    }
}

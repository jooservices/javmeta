<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Contracts\ReportChannel;
use App\Models\Movie;
use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use App\Services\Settings\SettingsService;
use Tests\TestCase;

final class ReportUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_preview_and_send_report_now_and_history_keeps_raw_numbers(): void
    {
        config(['cache.default' => 'array', 'javmeta_report.sections' => ['movies'], 'javmeta_report.channels' => ['telegram']]);
        Cache::store('array')->flush();
        Movie::factory()->create(['crawled_at' => now(), 'first_seen_at' => now()]);
        $channel = new class implements ReportChannel {
            /** @var list<array{subject: string, body: string, context: array<string, mixed>}> */
            public array $messages = [];

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->messages[] = ['subject' => $subject, 'body' => $body, 'context' => $context];

                return true;
            }
        };
        $this->app->instance(ReportChannel::class, $channel);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));

        $preview = $this->getJson('/ui-admin/report/periodic')->assertOk();
        $preview->assertJsonPath('data.data.movies.total', 1);
        $preview->assertJsonPath('data.last', null);
        self::assertStringContainsString('📊 [XCrawler] Report', $preview->json('data.text'));

        $sent = $this->postJson('/ui-admin/report/periodic/send-now')->assertOk();
        $sent->assertJsonPath('data.sent', true);
        self::assertCount(1, $channel->messages);
        self::assertTrue($channel->messages[0]['context']['plain_report']);
        self::assertSame('sent', ReportSnapshot::query()->latest('id')->value('result'));
        self::assertSame(1, ReportSnapshot::query()->latest('id')->firstOrFail()->getAttribute('raw_numbers')['movies']['total']);
        $this->getJson('/ui-admin/report/periodic')->assertJsonCount(1, 'data.history');
    }

    public function test_report_settings_accept_only_supported_intervals_and_valid_time_values(): void
    {
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));
        $allowed = [15, 30, 60, 120, 180, 360, 720, 1440];
        foreach ($allowed as $interval) {
            $this->putJson('/ui-admin/settings', ['settings' => ['report.interval_minutes' => $interval]])->assertOk();
        }

        $this->putJson('/ui-admin/settings', ['settings' => ['report.interval_minutes' => 45]])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['report.interval_minutes']]]);
        $this->putJson('/ui-admin/settings', ['settings' => ['report.timezone' => 'Not/A-Timezone']])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['report.timezone']]]);
        $this->putJson('/ui-admin/settings', ['settings' => ['report.quiet_hours_start' => '25:90']])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['report.quiet_hours_start']]]);
        $this->putJson('/ui-admin/settings', ['settings' => ['report.sections' => ['movies', 'not-a-section']]])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['report.sections']]]);
        $this->putJson('/ui-admin/settings', ['settings' => ['report.channels' => []]])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['report.channels']]]);
    }

    public function test_report_setting_registry_defines_database_defaults_and_vietnamese_help(): void
    {
        $definitions = config('javmeta_settings.definitions');

        self::assertSame('report', $definitions['report.enabled']['group']);
        self::assertSame(['javmeta_report.enabled'], $definitions['report.enabled']['config']);
        self::assertSame([15, 30, 60, 120, 180, 360, 720, 1440], $definitions['report.interval_minutes']['options']);
        self::assertSame(['movies', 'performers', 'crawl', 'sources', 'fleet', 'workers', 'servers', 'services'], $definitions['report.sections']['item_options']);
        self::assertSame(30, config('javmeta_report.history_days'));
        self::assertSame('America/Mexico_City', config('javmeta_report.timezone'));
        self::assertMatchesRegularExpression('/[\p{L}]/u', $definitions['report.interval_minutes']['label']);
        self::assertStringContainsString('Báo cáo', config('javmeta_settings.groups.report'));
    }
}

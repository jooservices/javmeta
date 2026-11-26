<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Contracts\ReportChannel;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelConfig\Contracts\ConfigStore;

final class AdministrationReportsTest extends AdministrationTestCase
{
    /** @return array<string, array<string, mixed>> a full, valid schedule body */
    private function body(): array
    {
        return [
            'periodic' => ['enabled' => true, 'every_minutes' => 360, 'include' => ['clusters', 'hosts']],
            'daily' => ['enabled' => true, 'time' => '08:00', 'timezone' => 'Asia/Ho_Chi_Minh', 'include' => ['clusters']],
            'quiet_mode' => ['enabled' => true, 'from' => '23:00', 'to' => '07:00'],
        ];
    }

    private function channel(bool $accepts): void
    {
        $this->app->instance(ReportChannel::class, new class ($accepts) implements ReportChannel {
            public function __construct(private readonly bool $accepts) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                return $this->accepts;
            }
        });
    }

    private function snapshot(array $attributes = []): int
    {
        return (int) DB::table('report_snapshots')->insertGetId($attributes + ['result' => 'sent', 'channel' => 'telegram', 'rendered_text' => 'text', 'raw_numbers' => '{}', 'report_type' => 'periodic', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_default_schedule_has_an_etag_and_all_three_blocks(): void
    {
        $this->admin();

        $response = $this->getJson('/api/v2/reports/schedule')->assertOk();

        $this->assertNotEmpty($response->headers->get('ETag'));
        $response->assertJsonStructure(['data' => ['periodic' => ['enabled', 'every_minutes', 'include'], 'daily' => ['enabled', 'time', 'timezone', 'include'], 'quiet_mode' => ['enabled', 'from', 'to']]]);
        $response->assertJsonPath('data.quiet_mode.enabled', false)->assertJsonPath('data.quiet_mode.from', '23:00');
    }

    public function test_a_schedule_is_saved_into_the_settings_and_read_back(): void
    {
        [, $csrf] = $this->admin();
        $etag = (string) $this->getJson('/api/v2/reports/schedule')->headers->get('ETag');

        $this->putJson('/api/v2/reports/schedule', $this->body(), $csrf)->assertStatus(428);
        $this->putJson('/api/v2/reports/schedule', $this->body(), $csrf + ['If-Match' => '"old"'])->assertStatus(412);

        $saved = $this->putJson('/api/v2/reports/schedule', $this->body(), $csrf + ['If-Match' => $etag])->assertOk();
        $saved->assertJsonPath('data.periodic.every_minutes', 360)->assertJsonPath('data.periodic.include', ['clusters', 'hosts'])->assertJsonPath('data.daily.include', ['clusters'])->assertJsonPath('data.quiet_mode.enabled', true);
        $this->assertNotSame($etag, $saved->headers->get('ETag'));

        $settings = app(SettingsService::class);
        $this->assertSame(360, $settings->get('report.interval_minutes'));
        $this->assertSame('08:00', $settings->get('report.daily_time'));
        $this->assertSame('23:00', $settings->get('report.quiet_hours_start'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'report_schedule.update')->count());
        $this->assertSame($saved->json('data'), $this->getJson('/api/v2/reports/schedule')->json('data'));
    }

    public function test_turning_quiet_mode_off_clears_the_hours_but_keeps_them_for_later(): void
    {
        [, $csrf] = $this->admin();
        $on = $this->body();
        $off = $this->body();
        $off['quiet_mode'] = ['enabled' => false, 'from' => '22:00', 'to' => '06:00'];
        $this->putJson('/api/v2/reports/schedule', $on, $csrf + ['If-Match' => (string) $this->getJson('/api/v2/reports/schedule')->headers->get('ETag')])->assertOk();

        $this->putJson('/api/v2/reports/schedule', $off, $csrf + ['If-Match' => (string) $this->getJson('/api/v2/reports/schedule')->headers->get('ETag')])->assertOk()
            ->assertJsonPath('data.quiet_mode', ['enabled' => false, 'from' => '22:00', 'to' => '06:00']);

        $this->assertSame('', app(SettingsService::class)->get('report.quiet_hours_start'));
    }

    public function test_the_schedule_is_validated_with_the_codes_of_the_contract(): void
    {
        [, $csrf] = $this->admin();
        $headers = fn(): array => $csrf + ['If-Match' => (string) $this->getJson('/api/v2/reports/schedule')->headers->get('ETag')];

        $bad = $this->body();
        $bad['daily']['timezone'] = 'Mars/Base';
        $bad['daily']['time'] = '25:00';
        $bad['quiet_mode']['from'] = 'late';
        $bad['periodic']['every_minutes'] = 5;
        $this->putJson('/api/v2/reports/schedule', $bad, $headers())->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonFragment(['field' => 'daily.timezone', 'code' => 'invalid_timezone'])
            ->assertJsonFragment(['field' => 'daily.time', 'code' => 'invalid_time'])
            ->assertJsonFragment(['field' => 'quiet_mode.from', 'code' => 'invalid_time'])
            ->assertJsonFragment(['field' => 'periodic.every_minutes', 'code' => 'too_small', 'min' => 15]);

        $odd = $this->body();
        $odd['periodic']['every_minutes'] = 17;
        $this->putJson('/api/v2/reports/schedule', $odd, $headers())->assertStatus(422)->assertJsonFragment(['field' => 'periodic.every_minutes', 'code' => 'invalid']);

        $this->putJson('/api/v2/reports/schedule', [], $headers())->assertStatus(422)->assertJsonFragment(['field' => 'periodic', 'code' => 'required']);
        $wrong = $this->body();
        $wrong['periodic']['include'] = ['nope'];
        $this->putJson('/api/v2/reports/schedule', $wrong, $headers())->assertStatus(422)->assertJsonFragment(['field' => 'periodic.include.0', 'code' => 'in']);
    }

    public function test_a_broken_config_store_leaves_the_defaults(): void
    {
        $this->admin();
        $this->app->instance(ConfigStore::class, new class extends \JOOservices\LaravelConfig\Testing\FakeConfigStore {
            public function get(string $path, mixed $default = null): mixed
            {
                throw new \RuntimeException('down');
            }
        });
        $this->app->forgetInstance(\App\Services\Api\V2\Administration\ReportAdministration::class);

        $this->getJson('/api/v2/reports/schedule')->assertOk()->assertJsonPath('data.daily.enabled', true);
    }

    public function test_the_next_runs_follow_the_cycle_and_the_daily_time(): void
    {
        [, $csrf] = $this->admin();
        $etag = (string) $this->getJson('/api/v2/reports/schedule')->headers->get('ETag');
        $body = $this->body();
        $body['periodic']['every_minutes'] = 60;
        $this->putJson('/api/v2/reports/schedule', $body, $csrf + ['If-Match' => $etag])->assertOk();

        $response = $this->getJson('/api/v2/reports/previews')->assertOk();

        $response->assertJsonPath('data.daily_next', '08:00 Asia/Ho_Chi_Minh')->assertJsonPath('data.include', ['clusters', 'hosts']);
        $next = $response->json('data.periodic_next');
        $this->assertCount(3, $next);
        $this->assertGreaterThan(now()->timestamp, strtotime($next[0]));
        $this->assertSame(3600, strtotime($next[1]) - strtotime($next[0]));
        $this->assertSame(3600, strtotime($next[2]) - strtotime($next[1]));
    }

    public function test_the_history_is_paged_newest_first(): void
    {
        $this->admin();
        $old = $this->snapshot(['created_at' => now()->subDay(), 'rendered_text' => 'old']);
        $new = $this->snapshot(['scheduled_for' => now(), 'rendered_text' => 'new']);

        $page = $this->getJson('/api/v2/reports/deliveries?per_page=1')->assertOk();
        $page->assertJsonPath('data.0.id', $new)->assertJsonPath('data.0.rendered_text', 'new')->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.pages', 2);
        $this->assertNotNull($page->json('data.0.scheduled_for'));
        $this->getJson('/api/v2/reports/deliveries?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', $old)->assertJsonPath('data.0.scheduled_for', null);
        $this->getJson('/api/v2/reports/deliveries?per_page=0')->assertStatus(422);
    }

    public function test_sending_a_report_now_stores_it_and_answers_202_with_a_location(): void
    {
        [, $csrf] = $this->admin();
        $this->channel(true);

        $response = $this->postJson('/api/v2/reports/deliveries', [], $csrf)->assertStatus(202);

        $id = (int) $response->json('data.id');
        $response->assertHeader('Location', "/api/v2/reports/deliveries/{$id}")->assertJsonPath('data.result', 'sent')->assertJsonPath('data.channel', 'telegram');
        $this->assertNotSame('', $response->json('data.rendered_text'));
        $this->assertSame('sent', DB::table('report_snapshots')->where('id', $id)->value('result'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'report.send_now')->count());
    }

    public function test_a_report_the_channel_refuses_is_stored_as_failed(): void
    {
        [, $csrf] = $this->admin();
        $this->channel(false);

        $this->postJson('/api/v2/reports/deliveries', [], $csrf)->assertStatus(202)->assertJsonPath('data.result', 'failed')->assertJsonPath('data.note', 'The channel did not accept the report.');
    }

    public function test_a_report_that_is_already_being_sent_is_a_conflict(): void
    {
        [, $csrf] = $this->admin();
        $this->channel(true);
        $lock = Cache::lock('javmeta:report:send:periodic:manual', 300);
        $this->assertTrue($lock->get());

        $this->postJson('/api/v2/reports/deliveries', [], $csrf)->assertStatus(409)->assertJsonPath('code', 'report_not_sent');
        $lock->release();
    }

    public function test_the_data_reports_answer_and_an_unknown_type_is_404(): void
    {
        $this->admin();

        $this->getJson('/api/v2/reports/quality')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v2/reports/cost')->assertOk();
        $this->getJson('/api/v2/reports/sources')->assertOk();
        $this->getJson('/api/v2/reports/other')->assertNotFound()->assertJsonPath('code', 'report_not_found');
    }
}

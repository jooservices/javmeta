<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Contracts\AlertNotifier;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class BackupStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\Carbon::parse('2026-10-16 12:00:00 UTC'));
        Storage::fake('local');
        config()->set([
            'backup.manifest_path' => Storage::disk('local')->path('backups/latest.json'),
            'backup.stale_after_seconds' => 26 * 60 * 60,
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'javmeta_it',
            'openobserve.email' => 'backup-test@example.test',
            'openobserve.password' => 'test-only',
            'openobserve.timeout' => 1,
        ]);
        Http::fake([
            'openobserve.test/*' => Http::response(['code' => 200], 200),
        ]);
        Cache::flush();
    }

    public function test_it_publishes_a_fresh_backup_age_without_alerting(): void
    {
        $this->writeManifest(now()->subHour()->toIso8601String());
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldNotReceive('notify');
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-status')->assertSuccessful();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/v1/metrics')
                && str_contains($request->body(), 'javmeta_backup_last_success_age_seconds')
                && str_contains($request->body(), '3600');
        });
    }

    public function test_it_publishes_and_alerts_when_the_backup_is_older_than_twenty_six_hours(): void
    {
        $this->writeManifest(now()->subSeconds(26 * 60 * 60 + 1)->toIso8601String());
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(static fn(string $subject, string $body, array $context): bool => str_contains($subject, 'Database backup is stale')
                && str_contains($body, '93,601 seconds')
                && $context['backup_age_seconds'] === 93_601
                && $context['template_key'] === 'backup.stale')
            ->andReturn(true);
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-status')->assertFailed();

        Http::assertSent(fn($request): bool => str_contains($request->body(), 'javmeta_backup_last_success_age_seconds'));
    }

    public function test_it_throttles_the_stale_alert_and_sends_one_recovery_message(): void
    {
        $this->writeManifest(now()->subHours(30)->toIso8601String());
        $notifications = [];
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notify')->twice()->andReturnUsing(static function (string $subject, string $body, array $context) use (&$notifications): bool {
            $notifications[] = [$subject, $body, $context];

            return true;
        });
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-status')->assertFailed();
        $state = DB::table('notification_policy_events')->where('notification_key', 'backup.stale')->first();
        self::assertNotNull($state);
        self::assertNotNull($state->last_sent_at);
        self::assertNotNull($state->critical_sent_at);
        $this->artisan('javmeta:backup-status')->assertFailed();
        self::assertSame(1, DB::table('notification_policy_events')->where('notification_key', 'backup.stale')->count());

        $this->writeManifest(now()->subHour()->toIso8601String());
        $this->artisan('javmeta:backup-status')->assertSuccessful();
        $this->artisan('javmeta:backup-status')->assertSuccessful();

        self::assertCount(2, $notifications);
        self::assertStringContainsString('Database backup is stale', $notifications[0][0]);
        self::assertSame('backup.stale', $notifications[0][2]['template_key']);
        self::assertStringContainsString('Database backup recovered', $notifications[1][0]);
        self::assertSame('backup.recovered', $notifications[1][2]['template_key']);
    }

    public function test_it_publishes_the_missing_sentinel_when_the_manifest_has_no_created_at(): void
    {
        $path = Storage::disk('local')->path('backups/latest.json');
        Storage::disk('local')->makeDirectory('backups');
        file_put_contents($path, json_encode(['namespace' => 'javmeta_it'], JSON_THROW_ON_ERROR));

        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(static fn(string $subject, string $body, array $context): bool => str_contains($subject, 'Backup manifest is missing')
                && str_contains($body, 'No successful database backup manifest is available')
                && $context['alert_state'] === 'open'
                && $context['severity'] === 'critical'
                && $context['backup_age_seconds'] === -1
                && $context['template_key'] === 'backup.manifest_missing')
            ->andReturn(true);
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-status')->assertFailed();

        Http::assertSent(fn($request): bool => str_contains($request->body(), 'javmeta_backup_last_success_age_seconds')
            && str_contains($request->body(), '-1'));
    }

    public function test_it_publishes_the_missing_sentinel_and_alerts_when_no_manifest_exists(): void
    {
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->withArgs(static fn(string $subject, string $body, array $context): bool => str_contains($subject, 'Backup manifest is missing')
                && str_contains($body, 'No successful database backup manifest is available')
                && $context['alert_state'] === 'open'
                && $context['severity'] === 'critical'
                && $context['backup_age_seconds'] === -1
                && $context['template_key'] === 'backup.manifest_missing')
            ->andReturn(true);
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-status')->assertFailed();

        Http::assertSent(fn($request): bool => str_contains($request->body(), 'javmeta_backup_last_success_age_seconds')
            && str_contains($request->body(), '-1'));
    }

    public function test_it_alerts_and_publishes_the_missing_sentinel_for_an_invalid_manifest(): void
    {
        Storage::disk('local')->makeDirectory('backups');
        file_put_contents(Storage::disk('local')->path('backups/latest.json'), '{invalid json');
        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notify')->once()->andReturn(true);
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-status')->assertFailed();

        Http::assertSent(fn($request): bool => str_contains($request->body(), 'javmeta_backup_last_success_age_seconds')
            && str_contains($request->body(), '-1'));
    }

    private function writeManifest(string $createdAt): void
    {
        $path = Storage::disk('local')->path('backups/latest.json');
        Storage::disk('local')->makeDirectory('backups');
        file_put_contents($path, json_encode(['created_at' => $createdAt], JSON_THROW_ON_ERROR));
    }

    public function test_lenient_mode_exits_zero_when_the_backup_is_missing_but_still_reports_it(): void
    {
        config()->set('backup.manifest_path', sys_get_temp_dir() . '/javmeta-no-such-manifest-' . uniqid('', true) . '.json');

        $this->artisan('javmeta:backup-status', ['--lenient' => true])
            ->expectsOutputToContain('No successful backup manifest is available.')
            ->assertSuccessful();
    }
}

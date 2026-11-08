<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

/** Gap coverage for settings:worker-layout and settings:env-cleanup. */
final class SettingsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private FakeConfigStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsService::forgetDefaults();
        $this->store = new FakeConfigStore();
        $this->app->instance(ConfigStore::class, $this->store);
        $this->app->forgetInstance(SettingsService::class);
        config([
            'javmeta_queues.horizon_names' => ['listing', 'detail'],
            'javmeta_queues.browser_names' => [],
            'javmeta_queues.retry_names' => [],
            'javmeta_queues.workers_per_queue' => 2,
            'javmeta_queues.queue_sleep_seconds' => 3,
            'javmeta_queues.queue_tries' => 4,
            'javmeta_queues.queue_max_time' => 3600,
            'javmeta_queues.queue_max_jobs' => 500,
            'javmeta_queues.worker_timeout' => 120,
            'javmeta_queues.stop_grace_seconds' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();

        parent::tearDown();
    }

    public function test_env_cleanup_reports_when_no_legacy_key_has_a_database_setting(): void
    {
        $this->artisan('settings:env-cleanup', ['--dry-run' => true])
            ->expectsOutputToContain('No registered legacy environment keys have a database setting.')
            ->assertSuccessful();
    }

    public function test_worker_layout_prints_a_queue_table_by_default(): void
    {
        $this->artisan('settings:worker-layout')
            ->expectsOutputToContain('Queue')
            ->expectsOutputToContain('detail')
            ->expectsOutputToContain('listing')
            ->assertSuccessful();
    }

    public function test_worker_layout_json_lists_the_effective_values(): void
    {
        self::assertSame(0, Artisan::call('settings:worker-layout', ['--json' => true]));

        $values = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['detail' => 2, 'listing' => 2], $values['queues']);
        self::assertSame(3, $values['sleep']);
        self::assertSame(4, $values['tries']);
        self::assertSame(3600, $values['max_time']);
        self::assertSame(500, $values['max_jobs']);
        self::assertSame(120, $values['timeout']);
        self::assertSame(15, $values['stop_grace_seconds']);
    }

    public function test_worker_layout_shell_output_is_tab_separated_for_the_legacy_supervisor(): void
    {
        self::assertSame(0, Artisan::call('settings:worker-layout', ['--shell' => true]));

        $lines = explode("\n", trim(Artisan::output()));

        self::assertContains("QUEUE\tdetail\t2", $lines);
        self::assertContains("QUEUE\tlisting\t2", $lines);
        self::assertContains("SETTING\tsleep\t3", $lines);
        self::assertContains("SETTING\ttries\t4", $lines);
        self::assertContains("SETTING\tmax_time\t3600", $lines);
        self::assertContains("SETTING\tmax_jobs\t500", $lines);
        self::assertContains("SETTING\ttimeout\t120", $lines);
        self::assertContains("SETTING\tstop_grace_seconds\t15", $lines);
    }
}

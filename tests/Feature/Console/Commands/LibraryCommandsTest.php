<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

/** Gap coverage for library:scan: the switch, the scheduled hour and the scan warnings. */
final class LibraryCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $missingFolder;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config(['javmeta_alerts.hourly_report.timezone' => 'UTC']);
        $this->missingFolder = sys_get_temp_dir() . '/javmeta-missing-library-' . uniqid();
        app(SettingsService::class)->set('library.folders', $this->missingFolder);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();

        parent::tearDown();
    }

    public function test_it_does_nothing_but_say_so_while_the_library_scan_is_switched_off(): void
    {
        app(SettingsService::class)->set('library.enabled', false);

        $this->artisan('library:scan')
            ->expectsOutputToContain('The library scan is switched off.')
            ->assertSuccessful();
    }

    public function test_it_stays_silent_outside_the_configured_scan_hour(): void
    {
        $this->travelTo('2026-11-02T12:34:00Z');
        $settings = app(SettingsService::class);
        $settings->set('library.enabled', true);
        $settings->set('library.scan_hour', 3);

        $this->artisan('library:scan')
            ->doesntExpectOutputToContain('Folders')
            ->assertSuccessful();
    }

    public function test_it_scans_at_the_configured_hour_and_warns_about_an_unreadable_folder(): void
    {
        $this->travelTo('2026-11-02T03:10:00Z');
        $settings = app(SettingsService::class);
        $settings->set('library.enabled', true);
        $settings->set('library.scan_hour', 3);

        $this->artisan('library:scan')
            ->expectsOutputToContain('Folders 1, files 0 (new 0), matched 0, unmatched 0, missing 0.')
            ->expectsOutputToContain('Folder not readable: ' . $this->missingFolder)
            ->assertSuccessful();
    }

    public function test_force_scans_even_while_the_switch_is_off(): void
    {
        app(SettingsService::class)->set('library.enabled', false);

        $this->artisan('library:scan', ['--force' => true])
            ->expectsOutputToContain('Folders 1, files 0 (new 0), matched 0, unmatched 0, missing 0.')
            ->assertSuccessful();
    }
}

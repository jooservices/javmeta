<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Exceptions\MovieNotFoundException;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\Source;
use App\Services\Api\V2\Downloads\MovieLinkSearch;
use App\Services\Api\V2\Downloads\ServedFileLinks;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

/** The two narrow adapters the downloads area of the API uses over the crawler's link search and the owner's file server. */
final class DownloadAdaptersTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $this->dir = sys_get_temp_dir() . '/javmeta-served-' . uniqid();
        mkdir($this->dir, 0777, true);
        config(['javmeta_downloads.link_search_enabled' => true, 'javmeta_downloads.link_search_max_sources_per_run' => 2, 'javmeta_queues.download_search_queue' => 'download_search']);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function movie(string $code): void
    {
        $movie = Movie::factory()->create(['display_code' => $code]);
        MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => $code, 'code_normalized' => strtoupper(str_replace('-', '', $code)), 'kind' => MovieCode::KIND_DVD]);
    }

    public function test_starting_a_link_search_reports_that_it_was_queued(): void
    {
        Bus::fake();
        $this->movie('ADP-001');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);

        $this->assertTrue(app(MovieLinkSearch::class)->start('ADP-001', 'client-1'));
    }

    public function test_a_second_start_for_the_same_movie_is_not_queued_again(): void
    {
        Bus::fake();
        $this->movie('ADP-002');
        Source::factory()->create(['slug' => 'onejav', 'enabled' => true, 'base_url' => 'https://onejav.com']);
        $search = app(MovieLinkSearch::class);

        $this->assertTrue($search->start('ADP-002', 'client-1'));
        $this->assertFalse($search->start('ADP-002', 'client-1'));
    }

    public function test_a_search_that_is_switched_off_or_has_no_source_is_not_queued(): void
    {
        $this->movie('ADP-003');
        $search = app(MovieLinkSearch::class);

        $this->assertFalse($search->start('ADP-003', 'client-1'));

        config(['javmeta_downloads.link_search_enabled' => false]);
        $this->assertFalse($search->start('ADP-003', 'client-1'));
    }

    public function test_an_unknown_movie_code_is_a_domain_error_the_caller_can_translate(): void
    {
        $this->expectException(MovieNotFoundException::class);

        app(MovieLinkSearch::class)->start('NOPE-999', 'client-1');
    }

    public function test_a_file_in_a_served_folder_gets_a_signed_expiring_link(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('downloads.save_path', $this->dir);
        $settings->set('files.enabled', true);
        file_put_contents($this->dir . '/clip.mp4', 'x');

        $url = app(ServedFileLinks::class)->linkFor($this->dir . '/clip.mp4');

        $this->assertIsString($url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_no_link_while_the_file_server_is_switched_off(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('downloads.save_path', $this->dir);
        $settings->set('files.enabled', false);
        file_put_contents($this->dir . '/clip.mp4', 'x');

        $this->assertNull(app(ServedFileLinks::class)->linkFor($this->dir . '/clip.mp4'));
    }

    public function test_no_link_for_a_missing_file_a_folder_or_a_file_outside_the_served_folders(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('downloads.save_path', $this->dir);
        $settings->set('files.enabled', true);
        $links = app(ServedFileLinks::class);

        $this->assertNull($links->linkFor($this->dir . '/missing.mp4'));
        $this->assertNull($links->linkFor($this->dir));
        $this->assertNull($links->linkFor('/etc/hostname'));
        $this->assertNull($links->linkFor($this->dir . '/../' . basename($this->dir) . '/../../etc/hostname'));
    }
}

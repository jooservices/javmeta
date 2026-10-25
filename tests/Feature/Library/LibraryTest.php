<?php

declare(strict_types=1);

namespace Tests\Feature\Library;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\User;
use App\Services\Auth\ApiKeyService;
use App\Services\Library\LibraryScanner;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        $this->root = sys_get_temp_dir() . '/javmeta-library-' . uniqid();
        mkdir($this->root . '/a/deep', 0777, true);
        file_put_contents($this->root . '/a/[grp] SSIS-123 title.mp4', str_repeat('x', 5000));
        file_put_contents($this->root . '/a/deep/abp045.mkv', 'short');
        file_put_contents($this->root . '/a/random holiday video.mp4', 'nothing');
        file_put_contents($this->root . '/a/readme.txt', 'ignored');
        $this->movie('SSIS-123');
        $this->movie('ABP-045');
        $s = app(SettingsService::class);
        $s->set('library.folders', $this->root . '/a');
        $s->set('library.enabled', true);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function movie(string $code): Movie
    {
        $movie = Movie::factory()->create(['display_code' => $code]);
        MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => $code, 'code_normalized' => \App\Support\Code\NormalizedCode::from($code)->value(), 'kind' => MovieCode::KIND_DVD]);

        return $movie;
    }

    public function test_it_recognises_codes_in_file_names(): void
    {
        $scanner = app(LibraryScanner::class);

        $this->assertContains('SSIS123', $scanner->codesIn('[grp] SSIS-123 title.mp4'));
        $this->assertContains('ABP045', $scanner->codesIn('abp045.mkv'));
        $this->assertContains('ABP045', $scanner->codesIn('ABP_045_1080p.mp4'));
        $this->assertSame([], $scanner->codesIn('holiday.mp4'));
    }

    public function test_a_scan_matches_files_to_movies_lists_the_rest_and_records_size_and_hash(): void
    {
        $result = app(LibraryScanner::class)->scan();

        $this->assertSame(['folders' => 1, 'seen' => 3, 'new' => 3, 'matched' => 2, 'unmatched' => 1, 'missing' => 0, 'errors' => []], $result);
        $row = DB::table('library_files')->where('path', 'like', '%SSIS-123%')->first();
        $this->assertSame(5000, $row->size);
        $this->assertSame('SSIS-123', $row->code);
        $this->assertNotNull($row->movie_id);
        $this->assertSame(40, strlen($row->quick_hash));
        $this->assertNull(DB::table('library_files')->where('path', 'like', '%holiday%')->value('movie_id'));
    }

    public function test_scanning_twice_adds_nothing_and_a_vanished_file_is_marked_missing(): void
    {
        $scanner = app(LibraryScanner::class);
        $scanner->scan();
        $second = $scanner->scan();
        $this->assertSame(0, $second['new']);
        $this->assertSame(3, DB::table('library_files')->count());

        unlink($this->root . '/a/deep/abp045.mkv');
        $third = $scanner->scan();
        $this->assertSame(1, $third['missing']);
        $this->assertNotNull(DB::table('library_files')->where('path', 'like', '%abp045%')->value('missing_at'));
        $this->assertSame(3, DB::table('library_files')->count(), 'nothing is deleted');
    }

    public function test_an_unreadable_folder_is_reported_not_fatal(): void
    {
        app(SettingsService::class)->set('library.folders', $this->root . "/a\n/nonexistent/folder");

        $result = app(LibraryScanner::class)->scan();

        $this->assertSame(2, $result['folders']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(3, $result['seen']);
    }

    public function test_the_command_respects_the_switch_and_force(): void
    {
        app(SettingsService::class)->set('library.enabled', false);
        $this->artisan('library:scan')->expectsOutputToContain('switched off')->assertSuccessful();
        $this->assertSame(0, DB::table('library_files')->count());

        $this->artisan('library:scan', ['--force' => true])->expectsOutputToContain('matched 2')->assertSuccessful();
    }

    public function test_the_library_page_data_and_the_on_disk_filter(): void
    {
        app(LibraryScanner::class)->scan();
        $this->actingAs(User::factory()->create(['role' => 'super_administrator']));

        $summary = $this->getJson('/ui-me/library')->assertOk()->json('data');
        $this->assertSame(3, $summary['files']);
        $this->assertSame(2, $summary['matched']);
        $this->assertSame(1, $summary['unmatched']);
        $this->assertSame(5000 + 5 + 7, $summary['bytes']);
        $this->assertCount(1, $summary['by_folder']);
        $this->assertEqualsCanonicalizing(['SSIS-123', 'ABP-045'], $this->getJson('/ui-me/library/codes')->json('data'));
        $this->assertCount(1, $this->getJson('/ui-me/library/unmatched')->json('data'));

        $headers = ['X-API-Key' => app(ApiKeyService::class)->create('lib')->plaintext];
        $this->movie('NOT-001');
        $on = array_column($this->getJson('/api/v1/movies?on_disk=1&per_page=50', $headers)->assertOk()->json('data'), 'code');
        $off = array_column($this->getJson('/api/v1/movies?on_disk=0&per_page=50', $headers)->assertOk()->json('data'), 'code');
        $this->assertEqualsCanonicalizing(['SSIS-123', 'ABP-045'], $on);
        $this->assertSame(['NOT-001'], $off);
    }
    public function test_directory_and_file_symlinks_outside_root_and_cycles_are_skipped(): void
    {
        mkdir($this->root . '/outside');
        file_put_contents($this->root . '/outside/OUT-001.mp4', 'private');
        symlink($this->root . '/outside', $this->root . '/a/outside');
        symlink($this->root . '/a', $this->root . '/a/again');
        symlink($this->root . '/outside/OUT-001.mp4', $this->root . '/a/OUT-001.mp4');
        $result = app(LibraryScanner::class)->scan();
        $this->assertSame(3, $result['seen']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(0, DB::table('library_files')->where('path', 'like', '%OUT-001%')->count());
        $this->assertSame(3, DB::table('library_files')->count());
    }

    public function test_unreadable_subtree_keeps_previously_seen_rows(): void
    {
        $scanner = app(LibraryScanner::class);
        $scanner->scan();
        chmod($this->root . '/a/deep', 0000);
        clearstatcache();
        try {
            $result = $scanner->scan();
            $this->assertNotEmpty($result['errors']);
            $this->assertSame(0, $result['missing']);
            $this->assertNull(DB::table('library_files')->where('path', 'like', '%abp045%')->value('missing_at'));
        } finally {
            chmod($this->root . '/a/deep', 0777);
        }
    }
}

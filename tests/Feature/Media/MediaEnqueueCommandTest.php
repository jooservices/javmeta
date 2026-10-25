<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MediaEnqueueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_enqueue_skips_refused_files_and_processes_later_rows(): void
    {
        $refused = $this->file('/generated/underage.mp4');
        $accepted = $this->file('/generated/adult.mp4');

        $this->artisan('media:enqueue scenes --all')
            ->expectsOutput('Queued 1 file(s); skipped 1 refused file(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('media_jobs', ['library_file_id' => $refused]);
        $this->assertDatabaseHas('media_jobs', ['library_file_id' => $accepted, 'kind' => 'scenes']);
    }

    public function test_bulk_enqueue_skips_a_file_removed_after_the_batch_was_read(): void
    {
        $removed = $this->file('/generated/disappeared.mp4');
        $accepted = $this->file('/generated/adult.mp4');
        $changed = false;
        DB::listen(function ($query) use ($removed, &$changed): void {
            if (! $changed && str_contains($query->sql, 'select') && str_contains($query->sql, 'library_files') && str_contains($query->sql, 'limit 100')) {
                $changed = true;
                DB::table('library_files')->where('id', $removed)->update(['missing_at' => now()]);
            }
        });

        $this->artisan('media:enqueue scenes --all')
            ->expectsOutput('Queued 1 file(s); skipped 1 refused file(s).')
            ->assertSuccessful();

        $this->assertTrue($changed);
        $this->assertDatabaseMissing('media_jobs', ['library_file_id' => $removed]);
        $this->assertDatabaseHas('media_jobs', ['library_file_id' => $accepted]);
    }

    private function file(string $path): int
    {
        return DB::table('library_files')->insertGetId(['path' => $path, 'folder' => '/generated', 'size' => 12, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }
}

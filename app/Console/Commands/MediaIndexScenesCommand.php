<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\SceneSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class MediaIndexScenesCommand extends Command
{
    protected $signature = 'media:index-scenes';
    protected $description = 'Retry indexing scene descriptions whose vectors are not yet available';

    public function handle(SceneSearchService $search): int
    {
        DB::table('library_files')->whereExists(static function ($q): void {
            $q->selectRaw('1')->from('media_scenes')->whereColumn('media_scenes.library_file_id', 'library_files.id')->whereNotNull('description')->whereNull('embedding_indexed_at');
        })->orderBy('id')->chunkById(100, static function ($files) use ($search): void {
            foreach ($files as $file) {
                $search->indexFile((int) $file->id);
            }
        });
        $this->info('Scene index retry finished; unavailable vectors remain pending.');
        return self::SUCCESS;
    }
}

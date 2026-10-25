<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\MediaQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MediaEnqueueCommand extends Command
{
    protected $signature = 'media:enqueue {kind} {--file=} {--all} {--movie=}';
    protected $description = 'Queue local library media processing';

    public function handle(MediaQueue $queue): int
    {
        $kind = (string) $this->argument('kind');
        if (! in_array($kind, MediaQueue::KINDS, true) || (! $this->option('file') && ! $this->option('all') && ! $this->option('movie'))) {
            $this->error('Choose a supported kind and --file, --movie or --all.');
            return self::FAILURE;
        }
        $query = DB::table('library_files')->whereNull('missing_at')->orderBy('id');
        if ($this->option('file')) {
            $query->where('id', $this->option('file'));
        }
        if ($this->option('movie')) {
            $query->where('code', strtoupper((string) $this->option('movie')));
        }
        $count = 0;
        $skipped = 0;
        foreach ($query->lazyById(100) as $file) {
            try {
                $queue->enqueue($kind, (int) $file->id);
                $count++;
            } catch (ValidationException) {
                $skipped++;
            } catch (HttpException) {
                $skipped++;
            }
        }
        $this->info("Queued {$count} file(s); skipped {$skipped} refused file(s).");
        return self::SUCCESS;
    }
}

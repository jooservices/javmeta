<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class MediaStatusCommand extends Command
{
    protected $signature = 'media:status';
    protected $description = 'Show media queue counts';

    public function handle(): int
    {
        $this->table(['Status', 'Count'], DB::table('media_jobs')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get()->map(static fn($row): array => [$row->status, $row->total])->all());
        return self::SUCCESS;
    }
}

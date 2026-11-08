<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\MediaServiceRepository;
use Illuminate\Console\Command;

final class MediaStatusCommand extends Command
{
    protected $signature = 'media:status';
    protected $description = 'Show media queue counts';

    public function handle(): int
    {
        $this->table(['Status', 'Count'], app(MediaServiceRepository::class)->tableQuery('media_jobs')->selectRaw('status, COUNT(*) AS total')->groupBy('status')->get()->map(static fn($row): array => [$row->status, $row->total])->all());
        return self::SUCCESS;
    }
}

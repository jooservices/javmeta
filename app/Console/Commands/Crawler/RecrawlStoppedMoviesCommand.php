<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Movie;
use Illuminate\Console\Command;

final class RecrawlStoppedMoviesCommand extends Command
{
    protected $signature = 'crawler:recrawl-stopped {--reset : Reset all stopped movies so incomplete recrawls can resume}';

    protected $description = 'List or reset movies stopped after repeated incomplete recrawls.';

    public function handle(): int
    {
        $stopped = Movie::query()->where('recrawl_status', Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE);

        if ($this->option('reset')) {
            $reset = $stopped->update([
                'recrawl_attempts' => 0,
                'recrawl_status' => null,
            ]);

            $this->components->info("Reset {$reset} stopped movie(s).");

            return self::SUCCESS;
        }

        $movies = $stopped->orderBy('display_code')->get(['display_code', 'recrawl_attempts']);

        if ($movies->isEmpty()) {
            $this->components->info('No movies are stopped after incomplete recrawls.');

            return self::SUCCESS;
        }

        $this->table(
            ['Movie code', 'No-progress rounds'],
            $movies->map(fn(Movie $movie): array => [$movie->display_code, $movie->recrawl_attempts])->all(),
        );

        $this->components->info('Run crawler:recrawl-stopped --reset to allow these movies to be queued again.');

        return self::SUCCESS;
    }
}

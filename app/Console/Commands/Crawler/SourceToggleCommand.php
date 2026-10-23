<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\CrawlEvent;
use App\Models\Source;
use Illuminate\Console\Command;

final class SourceToggleCommand extends Command
{
    protected $signature = 'crawler:source-toggle {slug : Source slug} {state : on or off} {--by=cli : Who switches it (written to the audit event)}';

    protected $description = 'Switch a crawl source on or off in the database (the only switch; queued rows of a switched-off source wait).';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $state = strtolower((string) $this->argument('state'));
        if (! in_array($state, ['on', 'off'], true)) {
            $this->components->error('The state must be "on" or "off".');

            return self::INVALID;
        }

        $source = Source::query()->find($slug);
        if (! $source instanceof Source) {
            $this->components->error("Unknown source \"{$slug}\". Run crawler:sources to list them.");

            return self::FAILURE;
        }

        $enabled = $state === 'on';
        $was = (bool) $source->enabled;
        $source->forceFill(['enabled' => $enabled])->save();

        if ($was !== $enabled) {
            CrawlEvent::query()->create([
                'source_slug' => $slug,
                'kind' => CrawlEvent::KIND_SOURCE_TOGGLED,
                'url' => null,
                'detail' => ['from' => $was, 'to' => $enabled, 'by' => (string) $this->option('by')],
                'created_at' => now(),
            ]);
        }

        $this->components->info(sprintf('%s is now %s%s.', $slug, $enabled ? 'ON' : 'OFF', $was === $enabled ? ' (no change)' : ''));

        return self::SUCCESS;
    }
}

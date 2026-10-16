<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Events\CrawlIncidentOccurred;
use App\Models\CrawlQueue;
use App\Models\CrawlRun;
use App\Models\Source;
use App\Observability\ObservabilityEmitter;
use App\Services\Crawl\CrawlQueueService;
use App\Services\Crawl\SourceThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;

final class SeedCommand extends Command
{
    protected $signature = 'crawler:seed {--source=} {--limit=50}';

    protected $description = 'Enqueue crawl listing URLs for enabled JAVMeta sources.';

    public function handle(
        CrawlQueueService $queue,
        SourceThrottle $throttle,
        ObservabilityEmitter $observability,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $sourceFilter = $this->option('source');

        $sources = Source::query()
            ->where('enabled', true)
            ->when(is_string($sourceFilter) && $sourceFilter !== '', fn($query) => $query->where('slug', $sourceFilter))
            ->orderBy('priority')
            ->get();

        foreach ($sources as $source) {
            $this->enqueueSourceSeeds($source, $limit, $queue, $throttle, $observability);
        }

        return self::SUCCESS;
    }

    private function enqueueSourceSeeds(
        Source $source,
        int $limit,
        CrawlQueueService $queue,
        SourceThrottle $throttle,
        ObservabilityEmitter $observability,
    ): void {
        $run = CrawlRun::query()->create([
            'source_slug' => $source->slug,
            'started_at' => now(),
            'status' => 'running',
            'pages_fetched' => 0,
            'movies_new' => 0,
            'movies_updated' => 0,
            'failures' => 0,
            'proxy_requests' => 0,
            'proxy_bytes' => 0,
            'browser_fetches' => 0,
        ]);

        $queued = 0;
        $seeds = array_slice($this->seedUrls($source->slug), 0, $limit);

        foreach ($seeds as [$kind, $url]) {
            $beforeExists = CrawlQueue::query()->where('source_slug', $source->slug)->where('url', $url)->exists();
            $queue->enqueue($url, $source->slug, $kind);

            if (! $beforeExists) {
                $queued++;
            }
        }

        Event::dispatch(new CrawlIncidentOccurred(
            sourceSlug: $source->slug,
            kind: 'queue_tick',
            url: null,
            detail: ['queued' => $queued, 'gap_seconds' => $throttle->currentGap($source)],
        ));

        $run->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function seedUrls(string $sourceSlug): array
    {
        $seeds = [];

        foreach ($this->configUrlList($sourceSlug, 'movie_listing_urls', 'listing_urls') as $url) {
            $seeds[] = [CrawlQueue::KIND_LISTING, $url];
        }

        foreach ($this->configUrlList($sourceSlug, 'performer_listing_urls') as $url) {
            $seeds[] = [CrawlQueue::KIND_PERFORMER_LISTING, $url];
        }

        foreach ($this->configUrlList($sourceSlug, 'gallery_listing_urls') as $url) {
            $seeds[] = [CrawlQueue::KIND_LISTING, $url];
        }

        foreach ($this->configUrlList($sourceSlug, 'gallery_urls') as $url) {
            $seeds[] = [CrawlQueue::KIND_GALLERY, $url];
        }

        return $seeds;
    }

    /** @return list<string> */
    private function configUrlList(string $sourceSlug, string $primaryKey, ?string $fallbackKey = null): array
    {
        $urls = config("javmeta_sources.sources.{$sourceSlug}.{$primaryKey}", []);
        if ((! is_array($urls) || $urls === []) && $fallbackKey !== null) {
            $urls = config("javmeta_sources.sources.{$sourceSlug}.{$fallbackKey}", []);
        }

        if (! is_array($urls)) {
            return [];
        }

        return array_values(array_filter($urls, is_string(...)));
    }
}

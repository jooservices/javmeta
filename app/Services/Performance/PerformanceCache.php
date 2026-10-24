<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Repositories\MovieRepository;
use App\Services\Crawl\CrawlStatusService;
use Illuminate\Support\Facades\Cache;

final class PerformanceCache
{
    public function __construct(private readonly CrawlStatusService $status, private readonly MovieRepository $movies) {}

    /** @return array<string, mixed> */
    public function summary(bool $refresh = false): array
    {
        $build = function (): array {
            $data = $this->status->status();
            unset($data['sources']);

            return $data;
        };
        if ($refresh) {
            $data = $build();
            Cache::put('javmeta:status:summary', $data, 15);

            return $data;
        }

        return Cache::remember('javmeta:status:summary', 15, $build);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters = [], bool $refresh = false): array
    {
        $key = 'javmeta:facets:' . md5(json_encode($filters, JSON_THROW_ON_ERROR));
        if ($refresh) {
            $data = $this->movies->facets($filters);
            Cache::put($key, $data, 60);

            return $data;
        }

        return Cache::remember($key, 60, fn(): array => $this->movies->facets($filters));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\CrawlStatsRepository;
use App\Repositories\Api\V2\SourceOpsRepository;
use App\Services\Api\V2\Contracts\AuditLog;
use Carbon\CarbonImmutable;
use stdClass;

/** Crawl sources with their health, 24 hour counters and, for one source, 72 hours of history. */
final class SourceDirectory
{
    public function __construct(
        private readonly SourceOpsRepository $sources,
        private readonly CrawlStatsRepository $stats,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $now = CarbonImmutable::now();
        $totals = [];
        foreach ($this->stats->sourceTotalsSince($now->subHours(24)) as $row) {
            $totals[(string) $row->source_slug] = $row;
        }
        $running = $this->sources->claimedBySource();
        $runs = $this->sources->runsBySourceSince($now->subHour());

        return array_map(
            fn(stdClass $source): array => $this->view($source, $totals[(string) $source->slug] ?? null, $running[(string) $source->slug] ?? 0, $runs[(string) $source->slug] ?? 0),
            $this->sources->all(),
        );
    }

    /** @return array<string, mixed> */
    public function find(string $slug): array
    {
        $view = $this->one($slug);
        $history = array_map(static fn(stdClass $row): array => [
            'hour_at' => Iso::stamp($row->hour_at),
            'attempts' => (int) $row->attempts,
            'ok' => (int) $row->ok,
            'blocked' => (int) $row->blocked,
            'movies_new' => $row->movies_new === null ? null : (int) $row->movies_new,
            'avg_ms' => $row->avg_ms === null ? null : (int) round((float) $row->avg_ms),
        ], $this->stats->sourceHistory($slug, CarbonImmutable::now()->subHours(72)));

        return $view + ['history' => $history];
    }

    /** @return array<string, mixed> */
    public function setEnabled(string $slug, bool $enabled, ?int $actorId): array
    {
        $was = $this->sources->switchTo($slug, $enabled, $actorId === null ? 'api' : "user:{$actorId}");
        if ($was === null) {
            throw self::missing();
        }

        $this->audit->record($actorId, "sources/{$slug}", 'patch', ['enabled' => $enabled, 'was' => $was]);

        return $this->one($slug);
    }

    /** @return array<string, mixed> */
    private function one(string $slug): array
    {
        foreach ($this->all() as $view) {
            if ($view['slug'] === $slug) {
                return $view;
            }
        }

        throw self::missing();
    }

    private static function missing(): ProblemException
    {
        return ProblemException::notFound('source_not_found', 'No such source.');
    }

    /** @return array<string, mixed> */
    private function view(stdClass $source, ?stdClass $totals, int $running, int $runs): array
    {
        $enabled = (bool) $source->enabled;
        $attempts = (int) ($totals->attempts ?? 0);
        $blocked = (int) ($totals->blocked ?? 0);
        $timeout = (int) ($totals->timeout ?? 0);
        $errors = (int) ($totals->errors ?? 0);
        $moviesNew = (int) ($totals->movies_new ?? 0);

        return [
            'slug' => $source->slug,
            'name' => $source->name,
            'base_url' => $source->base_url,
            'enabled' => $enabled,
            'priority' => (int) $source->priority,
            'needs_proxy' => (bool) $source->needs_proxy,
            'browser_lane' => (bool) $source->browser_lane,
            'gap_seconds_current' => number_format((float) $source->gap_seconds_current, 2, '.', ''),
            'gap_seconds_default' => number_format((float) $source->gap_seconds_default, 2, '.', ''),
            'consecutive_failures' => (int) $source->consecutive_failures,
            'circuit_state' => $source->circuit_state,
            'last_success_at' => Iso::stamp($source->last_success_at),
            'last_error_at' => Iso::stamp($source->last_error_at),
            'last_error' => $source->last_error,
            'circuit_open_count' => (int) $source->circuit_open_count,
            'enabled_overridden' => false,
            'status' => self::status($enabled, (string) $source->circuit_state, $attempts, $blocked + $errors + $timeout),
            'lane' => $source->browser_lane ? 'browser' : 'direct',
            'running' => $running,
            'runs_1h' => $runs,
            'last_24h' => [
                'attempts' => $attempts,
                'ok' => (int) ($totals->ok ?? 0),
                'blocked' => $blocked,
                'timeout' => $timeout,
                'errors' => $errors,
                'movies_new' => $moviesNew,
                'movies_updated' => (int) ($totals->movies_updated ?? 0),
            ],
            'new_per_hour' => round($moviesNew / 24, 1),
        ];
    }

    private static function status(bool $enabled, string $circuit, int $attempts, int $failed): string
    {
        return match (true) {
            ! $enabled => 'disabled',
            $circuit !== 'closed' => 'blocked',
            $attempts > 0 && $failed / $attempts > 0.25 => 'degraded',
            default => 'stable',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Support\ConfigType;

/**
 * Login cookies per source, stored encrypted with jooservices/laravel-config
 * (group crawlerx_logins, key = source slug). Values never leave this class
 * except through cookies(), which crawlerx consumes.
 */
final readonly class SourceLoginCookieStore
{
    public const GROUP = 'crawlerx_logins';

    public function __construct(private ConfigStore $config) {}

    /**
     * @param  array<string, string>  $cookies
     */
    public function put(string $slug, array $cookies): CarbonImmutable
    {
        $updatedAt = CarbonImmutable::now();
        $payload = json_encode(['cookies' => $cookies, 'updated_at' => $updatedAt->toISOString()], JSON_THROW_ON_ERROR);
        $this->config->set($this->path($slug), $payload, ConfigType::Encrypted->value);

        return $updatedAt;
    }

    public function forget(string $slug): bool
    {
        return $this->config->forget($this->path($slug));
    }

    /**
     * Reads straight from MongoDB so long-lived workers see updates at once.
     *
     * @return array<string, string>
     */
    public function cookies(string $slug): array
    {
        $cookies = $this->payload($slug)['cookies'] ?? [];
        if (! is_array($cookies)) {
            return [];
        }

        $valid = [];
        foreach ($cookies as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $valid[$name] = $value;
            }
        }

        return $valid;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $slug): array
    {
        $raw = $this->config->fresh($this->path($slug));
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function path(string $slug): string
    {
        return self::GROUP . '.' . $slug;
    }
}

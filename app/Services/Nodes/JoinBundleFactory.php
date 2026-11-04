<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Nodes\JoinBundle;

/** Builds the installer answers a joining worker needs from this installation's own configuration. */
final class JoinBundleFactory
{
    public function for(string $nodeName, string $role): JoinBundle
    {
        $answers = [
            'NODE_ROLE' => $role,
            'JAVMETA_NODE_NAME' => $nodeName,
            'APP_KEY' => config('app.key'),
            'APP_URL' => config('app.url'),
            'REPO_URL' => config('javmeta_nodes.release_repo_url'),
            'REF' => config('javmeta_nodes.release_ref'),
            ...$this->postgres(),
            ...$this->mongo(),
            ...$this->redis(),
            ...$this->elasticsearch(),
            ...$this->openObserve(),
            'FLARESOLVERR_URL' => config('javmeta_health.startup.flaresolverr_url'),
            'PLAYWRIGHT_URL' => config('javmeta_health.startup.playwright_url'),
        ];

        return new JoinBundle($nodeName, $role, $this->strings($answers));
    }

    /** @return array<string, mixed> */
    private function postgres(): array
    {
        $database = (array) config('database.connections.pgsql', []);

        return [
            'DB_HOST' => $database['host'] ?? null,
            'DB_PORT' => $database['port'] ?? null,
            'DB_DATABASE' => $database['database'] ?? null,
            'DB_USERNAME' => $database['username'] ?? null,
            'DB_PASSWORD' => $database['password'] ?? null,
            'DB_SSLMODE' => $database['sslmode'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function mongo(): array
    {
        $mongo = (array) config('database.connections.mongodb', []);
        $dsn = (string) ($mongo['dsn'] ?? '');
        $parts = parse_url($dsn);
        parse_str(is_array($parts) ? (string) ($parts['query'] ?? '') : '', $query);

        return [
            'MONGO_URI' => $dsn,
            'MONGO_DATABASE' => $mongo['database'] ?? null,
            'MONGO_HOST' => is_array($parts) ? ($parts['host'] ?? null) : null,
            'MONGO_PORT' => is_array($parts) ? ($parts['port'] ?? null) : null,
            'MONGO_USERNAME' => is_array($parts) && isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'MONGO_PASSWORD' => is_array($parts) && isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            'MONGO_AUTH_SOURCE' => $query['authSource'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function redis(): array
    {
        $default = (array) config('database.redis.default', []);

        return [
            'REDIS_HOST' => $default['host'] ?? null,
            'REDIS_PORT' => $default['port'] ?? null,
            'REDIS_USERNAME' => $default['username'] ?? null,
            'REDIS_PASSWORD' => $default['password'] ?? null,
            'REDIS_DB' => $default['database'] ?? null,
            'REDIS_CACHE_DB' => config('database.redis.cache.database'),
            'REDIS_PREFIX' => config('database.redis.options.prefix'),
        ];
    }

    /** @return array<string, mixed> */
    private function elasticsearch(): array
    {
        return [
            'ELASTICSEARCH_HOST' => config('elasticsearch.host'),
            'ELASTICSEARCH_API_KEY' => config('elasticsearch.api_key'),
            'ELASTICSEARCH_VERIFY_SSL' => config('elasticsearch.verify_ssl') ? 'true' : 'false',
            'ELASTICSEARCH_MOVIES_INDEX' => config('elasticsearch.movies_index'),
            'ELASTICSEARCH_PERFORMERS_INDEX' => config('elasticsearch.performers_index'),
        ];
    }

    /** @return array<string, mixed> */
    private function openObserve(): array
    {
        return [
            'OPENOBSERVE_URL' => config('openobserve.url'),
            'OPENOBSERVE_ORG' => config('openobserve.org'),
            'OPENOBSERVE_EMAIL' => config('openobserve.email'),
            'OPENOBSERVE_PASSWORD' => config('openobserve.password'),
            'OPENOBSERVE_ENABLED' => config('openobserve.enabled') ? 'true' : 'false',
        ];
    }

    /**
     * @param array<string, mixed> $answers
     * @return array<string, string> only the answers that have a value, as strings
     */
    private function strings(array $answers): array
    {
        $clean = [];
        foreach ($answers as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $clean[$key] = (string) $value;
        }

        return $clean;
    }
}

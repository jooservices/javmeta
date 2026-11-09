<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Observability\ResponseCacheMetrics;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;
use Throwable;

final readonly class ApiResponseCache
{
    public function __construct(
        private ResponseCachePolicy $policy,
        private ResponseCacheEpoch $epoch,
        private ResponseCacheMetrics $metrics,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handleApi(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('javmeta.internal_ui') === true) {
            return $next($request);
        }

        $route = $request->route();
        $routeUri = $route->uri();
        $rule = $this->policy->forApiRoute($routeUri);
        if ($rule === null) {
            return $next($request);
        }

        $apiKey = $request->attributes->get('api_key');
        if (! is_object($apiKey) || ! isset($apiKey->id)) {
            return $next($request);
        }

        $scopes = is_array($apiKey->scopes ?? null) ? array_values(array_map('strval', $apiKey->scopes)) : [];
        sort($scopes);

        return $this->handleResponseCache(
            $request,
            $rule,
            $routeUri,
            $route->parameters(),
            'api-key:' . (string) $apiKey->id,
            $scopes,
            false,
            fn(): Response => $next($request),
        );
    }

    /** @param Closure(): Response $next */
    public function handleUi(Request $request, string $path, string $principal, bool $guest, Closure $next): Response
    {
        $rule = $this->policy->forUiPath($path);
        if ($rule === null || (! $guest && $principal === 'ui:owner:unknown')) {
            return $next();
        }

        return $this->handleResponseCache(
            $request,
            $rule,
            'ui-api/' . $path,
            [],
            $principal,
            [],
            $guest,
            $next,
        );
    }

    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $routeParameters
     * @param Closure(): Response $build
     */
    private function handleResponseCache(
        Request $request,
        ResponseCacheRule $rule,
        string $route,
        array $routeParameters,
        string $principal,
        array $scopes,
        bool $guest,
        Closure $build,
    ): Response {
        $ttl = $this->ttl($rule->class);
        if (! (bool) config('javmeta_response_cache.enabled', true)) {
            return $this->bypass($request, $build, $rule->class);
        }

        $context = $this->cacheContext($request, $rule, $route, $routeParameters, $principal, $scopes, $guest, $ttl);
        if ($context === null) {
            return $this->bypass($request, $build, $rule->class);
        }

        $fresh = $this->matchingEntry($context['entry'], $context['fingerprint'], false);
        if ($fresh !== null) {
            $this->metrics->record($rule->class, 'hits');

            return $this->cachedResponse($request, $fresh);
        }

        $stale = $this->matchingEntry($context['entry'], $context['fingerprint'], true);
        $this->metrics->record($rule->class, 'misses');
        $lock = $this->acquireLock($request, $rule, $context, $stale, $ttl, $build);
        if ($lock instanceof Response) {
            return $lock;
        }
        if (! $lock instanceof Lock) {
            if ($stale !== null) {
                $this->metrics->record($rule->class, 'stale_served');

                return $this->cachedResponse($request, $stale);
            }

            return $this->cacheBusyResponse();
        }

        return $this->rebuildUnderLock($request, $rule, $context, $lock, $ttl, $build);
    }

    /**
     * @param list<string> $scopes
     * @param array<string, mixed> $routeParameters
     * @return array{store: CacheRepository, key: string, fingerprint: string, entry: mixed}|null
     */
    private function cacheContext(
        Request $request,
        ResponseCacheRule $rule,
        string $route,
        array $routeParameters,
        string $principal,
        array $scopes,
        bool $guest,
        int $ttl,
    ): ?array {
        $query = $this->normalizedQuery($request, $rule);
        $normalizedRouteParameters = $this->normalizeValue($routeParameters, 0);
        if ($query === null || ! is_array($normalizedRouteParameters)) {
            return null;
        }

        try {
            $epoch = $this->epoch->current();
            $fingerprint = hash('sha256', json_encode([
                'version' => 2,
                'epoch' => $epoch,
                'route' => $route,
                'parameters' => $normalizedRouteParameters,
                'query' => $query,
                'locale' => $this->locale(),
                'principal' => $principal,
                'scopes' => $scopes,
                'guest' => $guest,
                'ttl' => $ttl,
                'stale_seconds' => max(0, (int) config('javmeta_response_cache.stale_seconds', 10)),
            ], JSON_THROW_ON_ERROR));
            $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));
            $slot = $this->slot($rule->class, $fingerprint);
            $key = $this->entryKey($rule->class, $slot);
            return ['store' => $store, 'key' => $key, 'fingerprint' => $fingerprint, 'entry' => $store->get($key)];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{store: CacheRepository, key: string, fingerprint: string, entry: mixed} $context
     * @param array<string, mixed>|null $stale
     * @param Closure(): Response $build
     */
    private function acquireLock(
        Request $request,
        ResponseCacheRule $rule,
        array $context,
        ?array &$stale,
        int $ttl,
        Closure $build,
    ): Lock|Response|null {
        $lock = null;
        $acquired = false;
        try {
            $lock = $this->lock(
                $context['store'],
                $this->lockKey($rule->class, $this->slot($rule->class, $context['fingerprint'])),
                max(5, (int) config('javmeta_response_cache.lock_seconds', 300)),
            );
            $acquired = $lock->get();
            $deadline = microtime(true) + max(0, (int) config('javmeta_response_cache.lock_wait_milliseconds', 750)) / 1000;

            while (! $acquired && microtime(true) < $deadline) {
                usleep(20_000);
                $candidate = $context['store']->get($context['key']);
                $fresh = $this->matchingEntry($candidate, $context['fingerprint'], false);
                if ($fresh !== null) {
                    $this->metrics->record($rule->class, 'hits');

                    return $this->cachedResponse($request, $fresh);
                }
                $stale = $this->matchingEntry($candidate, $context['fingerprint'], true);
                if ($stale !== null) {
                    $this->metrics->record($rule->class, 'stale_served');

                    return $this->cachedResponse($request, $stale);
                }
                $acquired = $lock->get();
            }
        } catch (Throwable) {
            if ($acquired && $lock !== null) {
                $this->releaseLockSafely($lock);
            }

            return $this->bypass($request, $build, $rule->class);
        }

        return $acquired ? $lock : null;
    }

    /** @param array{store: CacheRepository, key: string, fingerprint: string, entry: mixed} $context
     * @param Closure(): Response $build
     */
    private function rebuildUnderLock(
        Request $request,
        ResponseCacheRule $rule,
        array $context,
        Lock $lock,
        int $ttl,
        Closure $build,
    ): Response {
        try {
            try {
                $entry = $context['store']->get($context['key']);
            } catch (Throwable) {
                return $this->bypass($request, $build, $rule->class);
            }

            $fresh = $this->matchingEntry($entry, $context['fingerprint'], false);
            if ($fresh !== null) {
                $this->metrics->record($rule->class, 'hits');

                return $this->cachedResponse($request, $fresh);
            }

            $response = $build();
            $this->storeResponse($context['store'], $context['key'], $context['fingerprint'], $response, $ttl, $rule->class);

            return $this->decorate($request, $response);
        } finally {
            $this->releaseLockSafely($lock);
        }
    }

    private function releaseLockSafely(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // A Redis lock release failure must not turn a successful endpoint response into an error.
        }
    }

    private function lock(CacheRepository $store, string $key, int $seconds): Lock
    {
        $provider = $store->getStore();
        if (! $provider instanceof LockProvider) {
            throw new RuntimeException('The selected cache store does not support locks.');
        }

        return $provider->lock($key, $seconds);
    }

    private function storeResponse(CacheRepository $store, string $key, string $fingerprint, Response $response, int $ttl, string $class): bool
    {
        $body = (string) $response->getContent();
        $maxBytes = max(1, (int) config('javmeta_response_cache.max_entry_bytes', 131_072));
        if (
            $response->getStatusCode() !== Response::HTTP_OK
            || ! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'application/json')
            || $response->headers->has('Set-Cookie')
            || strlen($body) > $maxBytes
        ) {
            $this->metrics->record($class, 'bypass');

            return false;
        }

        try {
            $staleSeconds = max(0, (int) config('javmeta_response_cache.stale_seconds', 10));
            $store->put($key, [
                'fingerprint' => $fingerprint,
                'body' => $body,
                'content_type' => (string) $response->headers->get('Content-Type', 'application/json; charset=UTF-8'),
                'fresh_until' => now()->timestamp + $ttl,
            ], $ttl + $staleSeconds);
            $this->metrics->record($class, 'bytes', strlen($body));

            return true;
        } catch (Throwable) {
            $this->metrics->record($class, 'bypass');

            return false;
        }
    }

    /** @param array<string, mixed> $entry */
    private function cachedResponse(Request $request, array $entry): Response
    {
        $response = new Response(
            (string) $entry['body'],
            Response::HTTP_OK,
            ['Content-Type' => (string) ($entry['content_type'] ?? 'application/json; charset=UTF-8')],
        );

        return $this->decorate($request, $response);
    }

    private function bypass(Request $request, Closure $build, string $class): Response
    {
        $this->metrics->record($class, 'bypass');

        return $this->decorate($request, $build());
    }

    private function cacheBusyResponse(): Response
    {
        return response()->json([
            'type' => 'cache_busy',
            'title' => 'Temporarily unavailable',
            'status' => Response::HTTP_SERVICE_UNAVAILABLE,
            'detail' => 'A response for this request is being prepared. Please retry shortly.',
        ], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', '1')
            ->header('Cache-Control', 'private, no-store');
    }

    private function decorate(Request $request, Response $response): Response
    {
        $cacheControl = 'private, no-store';

        $this->varyByLanguage($response);
        $response->headers->set('Cache-Control', $cacheControl);
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $etag = '"' . hash('sha256', (string) $response->getContent()) . '"';
        $response->headers->set('ETag', $etag);
        if (! $this->matchesIfNoneMatch((string) $request->headers->get('If-None-Match', ''), $etag)) {
            return $response;
        }

        $notModified = new Response('', Response::HTTP_NOT_MODIFIED);
        $notModified->headers->set('ETag', $etag);
        $notModified->headers->set('Cache-Control', $cacheControl);
        $this->varyByLanguage($notModified);

        return $notModified;
    }

    private function varyByLanguage(Response $response): void
    {
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
        foreach ($vary as $header) {
            if (strcasecmp($header, 'Accept-Language') === 0) {
                return;
            }
        }
        $vary[] = 'Accept-Language';
        $response->headers->set('Vary', implode(', ', $vary));
    }

    private function matchesIfNoneMatch(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (str_starts_with($candidate, 'W/')) {
                $candidate = trim(substr($candidate, 2));
            }
            if ($candidate !== '' && hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function normalizedQuery(Request $request, ResponseCacheRule $rule): ?array
    {
        $query = $rule->defaults;
        $rawQuery = $this->normalizeValue($request->query->all(), 0);
        if (! is_array($rawQuery)) {
            return null;
        }
        foreach ($rule->queryKeys as $key) {
            if ($key === 'lang') {
                $query[$key] = $this->locale();

                continue;
            }
            if (array_key_exists($key, $request->query->all())) {
                $value = $request->query($key);
                $normalized = $this->normalizeValue($value, 0);
                if ($normalized === null) {
                    return null;
                }
                $query[$key] = $normalized;
            }
        }

        if ($rule->maxPerPage !== null && isset($query['per_page']) && is_numeric($query['per_page']) && (int) $query['per_page'] > $rule->maxPerPage) {
            return null;
        }

        ksort($query);
        $query = ['defaults' => $query, 'raw' => $rawQuery];
        try {
            $encoded = json_encode($query, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return strlen($encoded) <= max(1, (int) config('javmeta_response_cache.max_query_bytes', 2_048)) ? $query : null;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > 2) {
            return null;
        }
        if (is_string($value)) {
            return strlen($value) <= 1_024 ? $value : null;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (! is_array($value) || count($value) > 100) {
            return null;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $normalized = $this->normalizeValue($item, $depth + 1);
            if ($normalized === null && $item !== null) {
                return null;
            }
            $value[$key] = $normalized;
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function matchingEntry(mixed $entry, string $fingerprint, bool $allowStale): ?array
    {
        if (! is_array($entry) || ! isset($entry['fingerprint'], $entry['body'], $entry['fresh_until'])) {
            return null;
        }
        if (! is_string($entry['fingerprint']) || ! hash_equals($fingerprint, $entry['fingerprint']) || ! is_string($entry['body']) || ! is_numeric($entry['fresh_until'])) {
            return null;
        }

        $now = now()->timestamp;
        $freshUntil = (int) $entry['fresh_until'];
        $staleUntil = $freshUntil + max(0, (int) config('javmeta_response_cache.stale_seconds', 10));
        if ($now <= $freshUntil || ($allowStale && $now <= $staleUntil)) {
            return $entry;
        }

        return null;
    }

    private function slot(string $class, string $fingerprint): int
    {
        $slots = max(1, (int) config('javmeta_response_cache.slots_per_class', 64));

        return (int) hexdec(substr($fingerprint, 0, 8)) % $slots;
    }

    private function entryKey(string $class, int $slot): string
    {
        return sprintf('javmeta:api-response:v1:%s:%d', $class, $slot);
    }

    private function lockKey(string $class, int $slot): string
    {
        return sprintf('javmeta:api-response:lock:v1:%s:%d', $class, $slot);
    }

    private function ttl(string $class): int
    {
        $default = (int) config('javmeta_response_cache.defaults.' . $class, 10);

        return max(1, (int) config('javmeta_response_cache.ttl.' . $class, $default));
    }

    private function locale(): string
    {
        $locale = (string) app()->getLocale();

        return in_array($locale, ['en', 'vi'], true) ? $locale : 'vi';
    }
}

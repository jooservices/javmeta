<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only bridge for the UI: `/ui-api/<path>` runs `/api/v1/<path>` inside the same process for the signed-in owner,
 * so the browser never holds an API key and the UI uses exactly the public API contract.
 */
final class UiApiController
{
    /** Paths the UI may read (GET only). */
    private const ALLOWED = '#^(movies|performers|meta|status|suggest|search|scenes)(/|$)#';

    /** Guest users receive only public catalogue operations with public-safe status projections. */
    private const GUEST_ALLOWED = '#^(?:'
        . 'movies(?:/facets|/calendar|/[A-Za-z0-9._-]+(?:/related)?)?|'
        . 'performers(?:/facets|/(?:[0-9]+|[A-Fa-f0-9]{8}-(?:[A-Fa-f0-9]{4}-){3}[A-Fa-f0-9]{12}))?|'
        . 'meta/(?:genres|catalog/(?:genres|makers|series|labels|years|cups|blood_types|locations))|'
        . 'search|suggest|status/(?:summary|sources)'
        . ')$#D';

    public function __invoke(Request $request, string $path): Response
    {
        $guest = (bool) $request->attributes->get('javmeta.guest_mode', false);
        if (! $request->isMethod('GET')) {
            abort_if($guest, 403);

            abort(404);
        }

        if ($guest) {
            abort_unless(self::guestPathAllowed($path), 403);
            abort_if($request->query->has('on_disk'), 403);
        } else {
            abort_unless(preg_match(self::ALLOWED, $path) === 1, 404);
        }

        $internal = Request::create(
            '/api/v1/' . $path,
            'GET',
            $request->query(),
            [],
            [],
            ['REMOTE_ADDR' => $request->ip() ?? '127.0.0.1', 'HTTP_ACCEPT' => 'application/json'],
        );
        $internal->attributes->set('javmeta.internal_ui', true);

        $response = app('router')->dispatch($internal);
        $response->headers->set('Cache-Control', 'no-store');

        if ($guest && $response instanceof JsonResponse) {
            $this->removePrivateGuestFields($path, $response);
        }

        return $response;
    }

    public static function guestPathAllowed(string $path): bool
    {
        return preg_match(self::GUEST_ALLOWED, $path) === 1;
    }

    private function removePrivateGuestFields(string $path, JsonResponse $response): void
    {
        $payload = $response->getData(true);
        if (! is_array($payload)) {
            return;
        }

        if ($path === 'status/summary' && is_array($payload['data'] ?? null)) {
            $payload['data'] = array_intersect_key($payload['data'], array_flip(['checked_at', 'counts', 'ingest', 'queue', 'workers', 'runs_24h']));
            if (is_array($payload['data']['queue'] ?? null)) {
                $payload['data']['queue'] = array_intersect_key($payload['data']['queue'], array_flip(['by_status', 'stuck_claimed']));
            }
            if (is_array($payload['data']['runs_24h'] ?? null)) {
                $payload['data']['runs_24h'] = array_intersect_key(
                    $payload['data']['runs_24h'],
                    array_flip(['runs', 'pages_fetched', 'movies_new', 'movies_updated', 'failures']),
                );
            }
            $payload['data']['workers'] = $this->publicWorkers($payload['data']['workers'] ?? []);
        } elseif ($path === 'status/sources' && is_array($payload['data'] ?? null)) {
            $safeFields = array_flip([
                'slug', 'name', 'enabled', 'lane', 'gap_seconds_current', 'gap_seconds_min', 'gap_seconds_max',
                'last_success_at', 'last_error_at', 'queue',
            ]);
            $payload['data'] = array_map(
                static function (array $source) use ($safeFields): array {
                    $lastSuccess = $source['last_success_at'] ?? null;
                    $lastError = $source['last_error_at'] ?? null;
                    $public = array_intersect_key($source, $safeFields);
                    unset($public['last_error_at']);
                    $public['has_recent_failure'] = $lastError !== null
                        && ($lastSuccess === null || strtotime((string) $lastError) > strtotime((string) $lastSuccess));

                    return $public;
                },
                array_filter($payload['data'], 'is_array'),
            );
        } elseif (preg_match('#^movies/[A-Za-z0-9._-]+$#D', $path) === 1 && is_array($payload['data'] ?? null)) {
            unset($payload['data']['magnets'], $payload['data']['hls_stream_urls'], $payload['data']['gallery'], $payload['data']['photos']);
        }

        $payload = $this->redactPrivateFields($payload);
        $response->setData($payload);
    }

    /** @param mixed $workers
     * @return list<array{instance: string, stale: bool, active_claims: int}>
     */
    private function publicWorkers(mixed $workers): array
    {
        if (! is_array($workers)) {
            return [];
        }

        $public = [];
        foreach (array_values(array_filter($workers, 'is_array')) as $index => $worker) {
            $public[] = [
                'instance' => 'Worker ' . ($index + 1),
                'stale' => (bool) ($worker['stale'] ?? false),
                'active_claims' => (int) ($worker['active_claims'] ?? 0),
            ];
        }

        return $public;
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function redactPrivateFields(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            if (preg_match('/(?:api.?key|(?:private|access|secret|signing|encryption|client).?key|key.?hash|token|password|secret|credential|cookie|authorization|headers|last.?error|internal|base.?url|file.?path|local.?path|storage.?path|library.?file.?id|file.?id|magnet|hls|stream.?url|play.?url|download.?url|gallery|photos|path|marks?|saved.?search|follows?|signature|expires)/', $normalizedKey) === 1) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = $this->redactPrivateFields($item);
            } elseif (is_string($item)) {
                $safe = $this->redactInternalReferences($item);
                if ($safe === null) {
                    unset($value[$key]);
                } else {
                    $value[$key] = $safe;
                }
            }
        }

        return $value;
    }

    private function isInternalUrl(string $value): bool
    {
        $parts = parse_url(trim($value));
        $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]');
        if ($host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return true;
        }

        if (in_array($host, ['localhost', 'host.docker.internal'], true)
            || ! str_contains($host, '.')
            || preg_match('/(?:\.local|\.internal|\.localhost|\.lan|\.test)$/', $host) === 1) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }

    private function redactInternalReferences(string $value): ?string
    {
        if (preg_match('/(?:file:\/\/|[?&](?:x-amz-)?(?:signature|sig|token|expires|credential|api[_-]?key|x-api-key|authorization|hmac)=|\/files\/download(?:\/|\?|$)|\/storage\/)/i', $value) === 1) {
            return null;
        }

        if ($this->isInternalUrl($value)) {
            return null;
        }

        $value = preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            fn(array $match): string => $this->isInternalUrl($match[0]) ? '[redacted internal URL]' : $match[0],
            $value,
        ) ?? $value;

        $value = preg_replace('~(^|[\s:=])/(?!/)[^\s"\'<>]+~', '$1[redacted path]', $value) ?? $value;

        return preg_replace('~\b[A-Za-z]:\\\\[^\s"\'<>]+~', '[redacted path]', $value) ?? $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Observability\Sentry;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

final class SentryScrubber
{
    private const SAFE_BREADCRUMB_METADATA = [
        'method',
        'status',
        'status_code',
        'reason',
        'duration',
        'connectionName',
        'executionTimeMs',
        'job',
        'queue',
        'attempts',
        'connection',
        'resolved',
    ];

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        $safeRequest = [];

        if (isset($request['url']) && is_string($request['url'])) {
            $safeRequest['url'] = self::stripUrlSecrets($request['url']);
        }

        if (isset($request['method']) && is_string($request['method'])) {
            $safeRequest['method'] = $request['method'];
        }

        $event->setRequest($safeRequest);
        $event->setUser(null);
        $event->setExtra([]);

        $tags = $event->getTags();
        foreach ($tags as $key => $value) {
            if (preg_match('/authorization|cookie|api[_ -]?key|token|secret|password/i', $key) === 1) {
                unset($tags[$key]);

                continue;
            }

            $tags[$key] = self::scrubText($value);
        }
        $event->setTags($tags);

        $breadcrumbs = [];
        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            $breadcrumbs[] = self::beforeBreadcrumb($breadcrumb);
        }
        $event->setBreadcrumb($breadcrumbs);

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::scrubText($exception->getValue()));

            $stacktrace = $exception->getStacktrace();
            if ($stacktrace !== null) {
                foreach ($stacktrace->getFrames() as $frame) {
                    $frame->setVars([]);
                }
            }
        }

        if ($event->getMessage() !== null) {
            $event->setMessage(self::scrubText($event->getMessage()), [], self::scrubText($event->getMessage()));
        }

        return SentryContext::applyToEvent($event);
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $metadata = [];

        foreach (self::SAFE_BREADCRUMB_METADATA as $key) {
            if (! array_key_exists($key, $breadcrumb->getMetadata())) {
                continue;
            }

            $value = $breadcrumb->getMetadata()[$key];
            if (is_scalar($value) || $value === null) {
                $metadata[$key] = $value;
            }
        }

        if (isset($breadcrumb->getMetadata()['url']) && is_string($breadcrumb->getMetadata()['url'])) {
            $metadata['url'] = self::stripUrlSecrets($breadcrumb->getMetadata()['url']);
        }

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            null,
            $metadata,
            $breadcrumb->getTimestamp(),
        );
    }

    private static function scrubText(string $value): string
    {
        $value = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [Filtered]', $value) ?? $value;
        $value = preg_replace(
            '/(["\']?(?:authorization|cookie|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|token|secret|password)["\']?\s*[:=]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            '$1[Filtered]',
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            static fn(array $matches): string => self::stripUrlSecrets($matches[0]),
            $value,
        ) ?? $value;
    }

    private static function stripUrlSecrets(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return (string) preg_replace('/[?#].*$/', '', $url);
        }

        $safe = '';
        if (isset($parts['scheme'])) {
            $safe .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $safe .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $safe .= ':' . $parts['port'];
        }

        return $safe . ($parts['path'] ?? '');
    }
}

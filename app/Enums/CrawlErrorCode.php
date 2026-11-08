<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlErrorCode: string
{
    case Blocked = 'blocked';
    case Challenge = 'challenge';
    case ParseFailed = 'parse_failed';
    case UnsupportedUrl = 'unsupported_url';
    case AmbiguousUrl = 'ambiguous_url';
    case AdapterNotFound = 'adapter_not_found';
    case Soft404 = 'soft404';
    case NotFound = 'not_found';
    case Gone = 'gone';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case Network = 'network';
    case AuthRequired = 'auth_required';
    case SsrfBlocked = 'ssrf_blocked';
    case Unknown = 'unknown';
    case UnknownSource = 'unknown_source';
    case NormalizerMissing = 'normalizer_missing';
    case NormalizationFailed = 'normalization_failed';
    case WorkerFailed = 'worker_failed';
    case WorkerLost = 'worker_lost';
    case LegacyUnclassified = 'legacy_unclassified';

    public static function fromCrawlerx(?string $code): self
    {
        return self::tryFrom($code ?? '') ?? self::Unknown;
    }
}

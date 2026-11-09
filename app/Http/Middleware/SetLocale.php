<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale from ?lang, Accept-Language (the UI sends the language it is showing), or the owner's ui.locale setting.
 * Supported values are restricted to vi|en; Vietnamese is the product default when nothing matches.
 */
final class SetLocale
{
    public const SUPPORTED = ['vi', 'en'];

    public const DEFAULT = 'vi';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        $fromQuery = $this->normalize((string) $request->query('lang', ''));
        if ($fromQuery !== null) {
            return $fromQuery;
        }

        $fromHeader = $this->fromAcceptLanguage((string) $request->header('Accept-Language', ''));
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        $fromSetting = $this->fromOwnerSetting();
        if ($fromSetting !== null) {
            return $fromSetting;
        }

        return self::DEFAULT;
    }

    private function fromOwnerSetting(): ?string
    {
        try {
            $user = Auth::user();
        } catch (\Throwable) {
            return null;
        }
        if ($user === null) {
            return null;
        }

        $stored = (string) config('javmeta.ui_locale', '');

        return $this->normalize($stored);
    }

    /** The supported language with the highest non-zero quality value; the first one listed wins a tie. */
    private function fromAcceptLanguage(string $header): ?string
    {
        $best = null;
        $bestQuality = 0.0;
        foreach (explode(',', $header) as $part) {
            $segments = explode(';', $part);
            $normalized = $this->normalize($segments[0]);
            if ($normalized === null) {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $match) === 1) {
                    $quality = (float) $match[1];
                }
            }
            if ($quality > $bestQuality) {
                $best = $normalized;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    private function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        $base = explode('-', $value)[0];

        return in_array($base, self::SUPPORTED, true) ? $base : null;
    }
}

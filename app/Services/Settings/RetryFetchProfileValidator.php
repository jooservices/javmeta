<?php

declare(strict_types=1);

namespace App\Services\Settings;

use JOOservices\CrawlerX\Enums\FetchMethod;

final class RetryFetchProfileValidator
{
    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    public function validate(array $value, callable $fail): void
    {
        $allowed = ['flaresolverr_url', 'playwright_url', 'http_proxy_url', 'egress_label', 'preferred_methods'];
        if (array_diff(array_keys($value), $allowed) !== []) {
            $fail('contains unsupported fetch profile fields');
        }
        foreach (['flaresolverr_url', 'playwright_url', 'http_proxy_url'] as $field) {
            $url = $value[$field] ?? null;
            if ($url === null || $url === '') {
                continue;
            }
            if (! is_string($url)) {
                $fail("{$field} must be an HTTP or HTTPS URL");
            }
            $parts = parse_url($url);
            if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || ! isset($parts['host'])) {
                $fail("{$field} must be an HTTP or HTTPS URL");
            }
            if (isset($parts['user']) || isset($parts['pass'])) {
                $fail("{$field} must not contain credentials; use an unauthenticated endpoint");
            }
            if (isset($parts['query']) || isset($parts['fragment'])) {
                $fail("{$field} must not contain a query string or fragment");
            }
        }
        $egress = $value['egress_label'] ?? null;
        if ($egress !== null && (! is_string($egress) || strlen($egress) > 64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $egress) !== 1)) {
            $fail('egress_label must use at most 64 letters, numbers, dots, underscores, or hyphens');
        }
        $methods = $value['preferred_methods'] ?? [];
        if (! is_array($methods) || ! array_is_list($methods) || count($methods) > count(FetchMethod::cases())) {
            $fail('preferred_methods must be an ordered list of fetch methods');
        }
        $allowedMethods = array_map(static fn(FetchMethod $method): string => $method->value, FetchMethod::cases());
        if (array_filter($methods, static fn(mixed $method): bool => ! is_string($method) || ! in_array($method, $allowedMethods, true)) !== []) {
            $fail('preferred_methods contains an unsupported fetch method');
        }
        if (count(array_unique($methods)) !== count($methods)) {
            $fail('preferred_methods must not contain duplicates');
        }
    }
}

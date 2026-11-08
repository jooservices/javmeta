<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Services\Settings\RetryFetchProfileValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryFetchProfileValidator::class)]
final class RetryFetchProfileValidatorTest extends TestCase
{
    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function validProfiles(): iterable
    {
        yield 'empty profile' => [[]];
        yield 'urls and label' => [[
            'flaresolverr_url' => 'http://flare.internal:8191',
            'playwright_url' => 'https://browser.internal/path',
            'http_proxy_url' => 'http://proxy.internal:8080',
            'egress_label' => 'wan-8.clean_ip',
        ]];
        yield 'blank urls are skipped' => [['flaresolverr_url' => '', 'playwright_url' => null]];
        yield 'ordered methods' => [['preferred_methods' => ['http', 'flaresolverr', 'playwright']]];
        yield 'no methods' => [['preferred_methods' => []]];
    }

    /** @param array<array-key, mixed> $profile */
    #[DataProvider('validProfiles')]
    public function test_a_valid_profile_raises_no_error(array $profile): void
    {
        self::assertSame([], $this->errors($profile));
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidProfiles(): iterable
    {
        yield 'unsupported field' => [['secret' => 'x'], 'contains unsupported fetch profile fields'];
        yield 'url is not a string' => [['playwright_url' => ['a']], 'playwright_url must be an HTTP or HTTPS URL'];
        yield 'url without a host' => [['http_proxy_url' => 'http://'], 'http_proxy_url must be an HTTP or HTTPS URL'];
        yield 'ftp scheme' => [['flaresolverr_url' => 'ftp://flare.internal'], 'flaresolverr_url must be an HTTP or HTTPS URL'];
        yield 'credentials in the url' => [['playwright_url' => 'http://user:pass@browser.internal'], 'playwright_url must not contain credentials; use an unauthenticated endpoint'];
        yield 'query string' => [['http_proxy_url' => 'http://proxy.internal?token=1'], 'http_proxy_url must not contain a query string or fragment'];
        yield 'fragment' => [['http_proxy_url' => 'http://proxy.internal#x'], 'http_proxy_url must not contain a query string or fragment'];
        yield 'label with a space' => [['egress_label' => 'bad label'], 'egress_label must use at most 64 letters, numbers, dots, underscores, or hyphens'];
        yield 'label too long' => [['egress_label' => str_repeat('a', 65)], 'egress_label must use at most 64 letters, numbers, dots, underscores, or hyphens'];
        yield 'label not a string' => [['egress_label' => 5], 'egress_label must use at most 64 letters, numbers, dots, underscores, or hyphens'];
        yield 'methods not a list' => [['preferred_methods' => ['a' => 'http']], 'preferred_methods must be an ordered list of fetch methods'];
        yield 'methods not an array' => [['preferred_methods' => 'http'], 'preferred_methods must be an ordered list of fetch methods'];
        yield 'unknown method' => [['preferred_methods' => ['telepathy']], 'preferred_methods contains an unsupported fetch method'];
        yield 'method not a string' => [['preferred_methods' => [1]], 'preferred_methods contains an unsupported fetch method'];
        yield 'duplicate methods' => [['preferred_methods' => ['http', 'http']], 'preferred_methods must not contain duplicates'];
    }

    /** @param array<array-key, mixed> $profile */
    #[DataProvider('invalidProfiles')]
    public function test_an_invalid_profile_reports_the_matching_message(array $profile, string $message): void
    {
        self::assertContains($message, $this->errors($profile));
    }

    /**
     * Collects every message the validator reports. The real callback never returns (Laravel's $fail throws),
     * so the collector throws a private marker to stop at the first one, like production does.
     *
     * @param array<array-key, mixed> $profile
     * @return list<string>
     */
    private function errors(array $profile): array
    {
        $messages = [];
        $fail = static function (string $message) use (&$messages): never {
            $messages[] = $message;

            throw new RuntimeException($message);
        };

        try {
            (new RetryFetchProfileValidator())->validate($profile, $fail);
        } catch (RuntimeException) {
            // The first failure stops validation, exactly like a Laravel $fail closure.
        }

        return $messages;
    }
}

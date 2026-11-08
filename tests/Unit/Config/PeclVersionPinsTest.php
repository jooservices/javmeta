<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHP extensions are pinned in every Dockerfile so environments cannot drift apart or break on a
 * bad release download. The pins must agree across images and with the extension that is loaded.
 */
final class PeclVersionPinsTest extends TestCase
{
    private const DOCKERFILES = ['Dockerfile', 'Dockerfile.production', 'docker/ci/Dockerfile'];

    private const REDIS_RELEASE_COMMIT = 'df4fab2de7fc327c54c94a13af2b9542e4fbd720';

    /**
     * @return iterable<string, array{string}>
     */
    public static function extensions(): iterable
    {
        yield 'mongodb' => ['mongodb'];
        yield 'redis' => ['redis'];
    }

    #[DataProvider('extensions')]
    public function test_every_dockerfile_pins_the_same_exact_version(string $extension): void
    {
        $versions = [];
        foreach (self::DOCKERFILES as $file) {
            $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pattern = $extension === 'redis'
                ? '/phpredis-(\d+\.\d+\.\d+)\.tar\.gz/'
                : '/pecl install[^\n]*\b' . $extension . '-(\d+\.\d+\.\d+)\b/';
            self::assertSame(1, preg_match($pattern, $contents, $match), "{$file} must pin {$extension} to an exact version.");
            $versions[$file] = $match[1];
        }

        self::assertCount(1, array_unique($versions), "{$extension} pins differ between images: " . json_encode($versions));
    }

    #[DataProvider('extensions')]
    public function test_the_loaded_extension_matches_the_pin(string $extension): void
    {
        if (! extension_loaded($extension)) {
            self::markTestSkipped("{$extension} is not loaded in this PHP.");
        }

        $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/Dockerfile');
        $pattern = $extension === 'redis'
            ? '/phpredis-(\d+\.\d+\.\d+)\.tar\.gz/'
            : '/pecl install[^\n]*\b' . $extension . '-(\d+\.\d+\.\d+)\b/';
        preg_match($pattern, $contents, $match);

        self::assertSame($match[1], phpversion($extension));
    }

    public function test_every_dockerfile_uses_the_immutable_phpredis_release_commit(): void
    {
        foreach (self::DOCKERFILES as $file) {
            $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            self::assertSame(
                1,
                preg_match('/phpredis\/phpredis\/archive\/([a-f0-9]{40})\.tar\.gz/', $contents, $match),
                "{$file} must download PhpRedis from an immutable source commit.",
            );
            self::assertSame(self::REDIS_RELEASE_COMMIT, $match[1], "{$file} must use the published PhpRedis 6.3.0 release commit.");
        }
    }

    public function test_the_ci_image_also_pins_pcov(): void
    {
        self::assertMatchesRegularExpression('/pecl install[^\n]*\bpcov-\d+\.\d+\.\d+\b/', (string) file_get_contents(dirname(__DIR__, 3) . '/docker/ci/Dockerfile'));
    }

    #[DataProvider('extensions')]
    public function test_installs_retry_a_failed_download_before_failing_the_build(string $extension): void
    {
        foreach (self::DOCKERFILES as $file) {
            $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $contents = preg_replace('/\\\\\\R\\s*/', ' ', $contents) ?? $contents;
            $pattern = $extension === 'redis'
                ? '/for attempt in 1 2 3; do\s+curl\b(?:(?!\bdone\b).)*?phpredis\/phpredis\/archive\/[a-f0-9]{40}\.tar\.gz(?:(?!\bdone\b).)*?&& break;/s'
                : '/for attempt in 1 2 3; do pecl install [^;]*\b' . $extension . '-\d+\.\d+\.\d+/';
            self::assertMatchesRegularExpression($pattern, $contents, "{$file} must retry the {$extension} download.");
        }
    }
}

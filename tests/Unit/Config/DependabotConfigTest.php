<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Dependabot must only watch ecosystems that exist, so it never proposes updates for removed tooling
 * and never silently misses a manifest that is added.
 */
final class DependabotConfigTest extends TestCase
{
    /** @var array<string, list<string>> ecosystem => manifest names that prove it is in use */
    private const MANIFESTS = [
        'composer' => ['composer.json'],
        'npm' => ['package.json'],
        'pip' => ['requirements.txt', 'requirements.production.txt'],
        'docker' => ['Dockerfile', 'Dockerfile.production'],
        'github-actions' => [],
    ];

    public function test_every_configured_directory_contains_a_manifest_for_its_ecosystem(): void
    {
        $root = dirname(__DIR__, 3);
        $config = Yaml::parseFile($root . '/.github/dependabot.yml');

        foreach ($config['updates'] as $update) {
            $ecosystem = $update['package-ecosystem'];
            $directories = $update['directories'] ?? [$update['directory']];
            self::assertArrayHasKey($ecosystem, self::MANIFESTS, "Unknown ecosystem {$ecosystem}.");

            foreach ($directories as $directory) {
                $path = $root . rtrim($directory, '/');
                if ($ecosystem === 'github-actions') {
                    self::assertDirectoryExists($root . '/.github/workflows');

                    continue;
                }

                $found = array_filter(self::MANIFESTS[$ecosystem], static fn(string $name): bool => is_file("{$path}/{$name}"));
                self::assertNotEmpty($found, "{$ecosystem} is configured for {$directory} but no manifest exists there.");
            }
        }
    }

    public function test_every_manifest_in_the_repository_is_watched(): void
    {
        $root = dirname(__DIR__, 3);
        $configured = array_map(static fn(array $update): string => $update['package-ecosystem'], Yaml::parseFile($root . '/.github/dependabot.yml')['updates']);

        foreach (['composer' => 'composer.json', 'npm' => 'package.json'] as $ecosystem => $manifest) {
            if (is_file($root . '/' . $manifest)) {
                self::assertContains($ecosystem, $configured, "{$manifest} exists but Dependabot does not watch {$ecosystem}.");
            }
        }
    }
}

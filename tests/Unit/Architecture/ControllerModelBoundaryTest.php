<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ControllerModelBoundaryTest extends TestCase
{
    public function test_controller_without_model_dependency_passes_architecture_analysis(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Services {
                final class AllowedFixtureService {}
            }

            namespace App\Http\Controllers\Fixtures {
                use App\Services\AllowedFixtureService;

                final class AllowedFixtureController
                {
                    public function __construct(private readonly AllowedFixtureService $service) {}

                    public function service(): AllowedFixtureService
                    {
                        return $this->service;
                    }
                }
            }
            PHP);

        self::assertSame(0, $analysis['exitCode'], $analysis['output']);
    }

    public function test_controller_model_dependency_fails_and_names_the_model(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models {
                final class ForbiddenFixtureModel
                {
                    public static function query(): void {}
                }
            }

            namespace App\Http\Controllers\Fixtures {
                use App\Models\ForbiddenFixtureModel;

                final class ForbiddenFixtureController
                {
                    public function show(): void
                    {
                        ForbiddenFixtureModel::query();
                    }
                }
            }
            PHP);

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('ForbiddenFixtureController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\ForbiddenFixtureModel', $analysis['output']);
    }

    public function test_existing_controller_exception_rejects_an_unlisted_model_dependency(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models {
                final class Movie
                {
                    public static function query(): void {}
                }

                final class Performer
                {
                    public static function query(): void {}
                }
            }

            namespace App\Http\Controllers {
                use App\Models\Movie;
                use App\Models\Performer;

                final class MovieLookupController
                {
                    public function show(): void
                    {
                        Movie::query();
                        Performer::query();
                    }
                }
            }
            PHP);

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('MovieLookupController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\Performer', $analysis['output']);
    }

    /**
     * @return array{exitCode: int|null, output: string}
     */
    private function analyseFixture(string $source): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $temporaryRoot = $projectRoot . '/.tmp';
        if (is_link($temporaryRoot) || (file_exists($temporaryRoot) && ! is_dir($temporaryRoot))) {
            throw new RuntimeException('The project .tmp path must be a real directory for architecture fixtures.');
        }

        if (! is_dir($temporaryRoot) && ! mkdir($temporaryRoot, 0700, true) && ! is_dir($temporaryRoot)) {
            throw new RuntimeException('Unable to create the project .tmp directory for architecture fixtures.');
        }

        $temporaryDirectory = $temporaryRoot . '/javmeta-controller-boundary-' . bin2hex(random_bytes(8));
        if (! mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException('Unable to create an isolated architecture fixture directory.');
        }

        try {
            $fixturePath = $temporaryDirectory . '/ControllerFixture.php';
            $configPath = $temporaryDirectory . '/phpstan.neon';
            $cachePath = $temporaryDirectory . '/cache';
            $config = sprintf(
                "includes:\n    - %s\nparameters:\n    paths:\n        - %s\n    tmpDir: %s\n",
                json_encode($projectRoot . '/tools/quality/phpstan-architecture.neon', JSON_THROW_ON_ERROR),
                json_encode($fixturePath, JSON_THROW_ON_ERROR),
                json_encode($cachePath, JSON_THROW_ON_ERROR),
            );

            if (file_put_contents($fixturePath, $source, LOCK_EX) === false
                || file_put_contents($configPath, $config, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write architecture fixture files.');
            }

            $process = new Process([
                PHP_BINARY,
                $projectRoot . '/vendor/bin/phpstan',
                'analyse',
                '--configuration=' . $configPath,
                '--no-progress',
                '--error-format=raw',
            ], $projectRoot);
            $process->setTimeout(30);
            $process->run();

            return [
                'exitCode' => $process->getExitCode(),
                'output' => $process->getErrorOutput() . $process->getOutput(),
            ];
        } finally {
            (new Filesystem())->remove($temporaryDirectory);
        }
    }
}

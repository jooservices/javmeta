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

    public function test_every_existing_exception_rejects_new_usage_of_its_baselined_model_pair(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Http\Controllers {
                use App\Models\CrawlQueue;
                use App\Models\Genre;
                use App\Models\Movie;
                use App\Models\Performer;
                use App\Models\User;

                final class MovieBulkController { public function probe(): void { Movie::query(); } }
                final class MovieLookupController { public function probe(): void { Movie::query(); } }
                final class MovieRelatedController
                {
                    public function probe(): void
                    {
                        Movie::query();
                        Performer::query();
                    }
                }
                final class PerformerController { public function probe(): void { Performer::query(); } }
                final class StatusController { public function probe(): void { CrawlQueue::query(); } }
                final class MetaGenreController { public function probe(): void { Genre::query(); } }
            }

            namespace App\Http\Controllers\Operations {
                use App\Models\User;

                final class LoginController { public function probe(): void { User::query(); } }
                final class NodeController { public function probe(): void { User::query(); } }
            }

            namespace App\Http\Controllers\Ui {
                use App\Models\User;

                final class UiNotificationTemplateController { public function probe(): void { User::query(); } }
                final class UiSettingsController { public function probe(): void { User::query(); } }
            }
            PHP);

        $pairs = [
            'App\\Http\\Controllers\\MovieBulkController' => ['App\\Models\\Movie'],
            'App\\Http\\Controllers\\MovieLookupController' => ['App\\Models\\Movie'],
            'App\\Http\\Controllers\\MovieRelatedController' => ['App\\Models\\Movie', 'App\\Models\\Performer'],
            'App\\Http\\Controllers\\PerformerController' => ['App\\Models\\Performer'],
            'App\\Http\\Controllers\\StatusController' => ['App\\Models\\CrawlQueue'],
            'App\\Http\\Controllers\\Operations\\LoginController' => ['App\\Models\\User'],
            'App\\Http\\Controllers\\Operations\\NodeController' => ['App\\Models\\User'],
            'App\\Http\\Controllers\\Ui\\UiNotificationTemplateController' => ['App\\Models\\User'],
            'App\\Http\\Controllers\\Ui\\UiSettingsController' => ['App\\Models\\User'],
            'App\\Http\\Controllers\\MetaGenreController' => ['App\\Models\\Genre'],
        ];

        foreach ($pairs as $controller => $models) {
            foreach ($models as $model) {
                self::assertStringContainsString(
                    sprintf('Controller %s must not depend directly on model %s', $controller, $model),
                    $analysis['output'],
                );
            }
        }
    }

    public function test_existing_exception_rejects_a_new_operation_on_the_baselined_model(): void
    {
        $controllerPath = dirname(__DIR__, 3) . '/app/Http/Controllers/MovieLookupController.php';
        $source = file_get_contents($controllerPath);
        if ($source === false) {
            throw new RuntimeException('Unable to read the MovieLookupController baseline fixture.');
        }

        $classClose = strrpos($source, "\n}");
        if ($classClose === false) {
            throw new RuntimeException('Unable to find the MovieLookupController class close for its fixture.');
        }

        $source = substr($source, 0, $classClose)
            . "\n\n    public function deleteAll(): void\n    {\n        Movie::query()->delete();\n    }"
            . substr($source, $classClose);
        $analysis = $this->analyseFixture($source, 'MovieLookupController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('MovieLookupController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\Movie', $analysis['output']);
    }

    public function test_baselined_genre_query_rejects_added_chained_operations(): void
    {
        $controllerPath = dirname(__DIR__, 3) . '/app/Http/Controllers/MetaGenreController.php';
        $source = file_get_contents($controllerPath);
        if ($source === false) {
            throw new RuntimeException('Unable to read the MetaGenreController baseline fixture.');
        }

        foreach (['where', 'delete'] as $operation) {
            $mutatedSource = $this->mutateGenreQuery($source, $operation);
            self::assertStringContainsString('$labels = Genre::query()', $mutatedSource);

            $analysis = $this->analyseFixture($mutatedSource, 'MetaGenreController.php');

            self::assertNotSame(0, $analysis['exitCode'], $operation . "\n" . $analysis['output']);
            self::assertStringContainsString('MetaGenreController', $analysis['output']);
            self::assertStringContainsString('App\\Models\\Genre', $analysis['output']);
        }
    }

    public function test_existing_exception_rejects_a_new_model_type_declaration(): void
    {
        $controllerPath = dirname(__DIR__, 3) . '/app/Http/Controllers/MovieLookupController.php';
        $source = file_get_contents($controllerPath);
        if ($source === false) {
            throw new RuntimeException('Unable to read the MovieLookupController baseline fixture.');
        }

        $classClose = strrpos($source, "\n}");
        if ($classClose === false) {
            throw new RuntimeException('Unable to find the MovieLookupController class close for its fixture.');
        }

        $source = substr($source, 0, $classClose)
            . "\n\n    public function acceptMovie(Movie \$movie): void {}"
            . substr($source, $classClose);
        $analysis = $this->analyseFixture($source, 'MovieLookupController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('MovieLookupController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\Movie', $analysis['output']);
    }

    public function test_existing_exception_rejects_a_new_phpdoc_model_parameter(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models {
                final class Movie {}
            }

            namespace App\Http\Controllers {
                use App\Models\Movie;

                final class MovieLookupController
                {
                    /** @param Movie $movie */
                    public function acceptMovie($movie): void {}
                }
            }
            PHP);

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('MovieLookupController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\Movie', $analysis['output']);
    }

    public function test_existing_exception_rejects_a_new_phpdoc_model_property(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models {
                final class Movie {}
            }

            namespace App\Http\Controllers {
                use App\Models\Movie;

                final class MovieLookupController
                {
                    /** @var Movie|null */
                    public $boundaryMovie = null;
                }
            }
            PHP, 'MovieLookupController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('MovieLookupController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\Movie', $analysis['output']);
    }

    public function test_controller_cannot_import_a_model_without_using_it_in_all_import_forms(): void
    {
        $fixtures = [
            'SimpleImportController.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Http\Controllers\Fixtures;

                use App\Models\ForbiddenFixtureModel;

                final class SimpleImportController {}
                PHP,
            'AliasedImportController.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Http\Controllers\Fixtures;

                use App\Models\ForbiddenFixtureModel as ForbiddenModel;

                final class AliasedImportController {}
                PHP,
            'GroupedImportController.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Http\Controllers\Fixtures;

                use App\Models\{ForbiddenFixtureModel as ForbiddenModel};

                final class GroupedImportController {}
                PHP,
            'NamespaceImportController.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace App\Http\Controllers\Fixtures;

                use App\Models as Models;

                final class NamespaceImportController {}
                PHP,
        ];

        foreach ($fixtures as $fileName => $source) {
            $analysis = $this->analyseFixture($source, $fileName);

            self::assertNotSame(0, $analysis['exitCode'], $fileName . "\n" . $analysis['output']);
            self::assertStringContainsString('App\\Models', $analysis['output'], $fileName);
        }
    }

    public function test_fully_qualified_model_reference_fails_architecture_analysis(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Http\Controllers\Fixtures;

            final class FullyQualifiedController
            {
                public function show(): string
                {
                    return \App\Models\Movie::class;
                }
            }
            PHP, 'FullyQualifiedController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('FullyQualifiedController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\Movie', $analysis['output']);
    }

    public function test_model_instance_method_and_property_access_fails_including_nullsafe_forms(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models {
                final class AccessFixtureModel
                {
                    public string $title;

                    public function remove(): void {}
                }
            }

            namespace App\Services {
                use App\Models\AccessFixtureModel;

                final class AccessFixtureService
                {
                    public function find(): AccessFixtureModel
                    {
                        return new AccessFixtureModel();
                    }
                }
            }

            namespace App\Http\Controllers\Fixtures {
                use App\Services\AccessFixtureService;

                final class ModelAccessController
                {
                    public function access(AccessFixtureService $service): void
                    {
                        $model = $service->find();
                        $model->remove();
                        $title = $model->title;
                        $model?->remove();
                        $title = $model?->title;
                    }
                }
            }
            PHP, 'ModelAccessController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertStringContainsString('ModelAccessController', $analysis['output']);
        self::assertStringContainsString('App\\Models\\AccessFixtureModel', $analysis['output']);
    }

    public function test_model_array_offset_reads_and_writes_fail_architecture_analysis(): void
    {
        $analysis = $this->analyseFixture(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models {
                final class AccessFixtureModel implements \ArrayAccess
                {
                    public function offsetExists(mixed $offset): bool { return true; }
                    public function offsetGet(mixed $offset): mixed { return 'title'; }
                    public function offsetSet(mixed $offset, mixed $value): void {}
                    public function offsetUnset(mixed $offset): void {}
                }
            }

            namespace App\Services {
                use App\Models\AccessFixtureModel;

                final class AccessFixtureService
                {
                    public function find(): AccessFixtureModel
                    {
                        return new AccessFixtureModel();
                    }
                }
            }

            namespace App\Http\Controllers\Fixtures {
                use App\Services\AccessFixtureService;

                final class ModelOffsetController
                {
                    public function access(AccessFixtureService $service): void
                    {
                        $model = $service->find();
                        $title = $model['title'];
                        $model['title'] = 'updated';
                    }
                }
            }
            PHP, 'ModelOffsetController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertSame(
            2,
            substr_count(
                $analysis['output'],
                'Controller App\\Http\\Controllers\\Fixtures\\ModelOffsetController must not depend directly on model App\\Models\\AccessFixtureModel',
            ),
            $analysis['output'],
        );
    }

    public function test_dynamic_model_class_strings_fail_for_static_calls_and_instantiation(): void
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

            namespace App\Services {
                use App\Models\ForbiddenFixtureModel;

                final class ModelClassStringService
                {
                    /** @return class-string<ForbiddenFixtureModel> */
                    public function modelClass(): string
                    {
                        return ForbiddenFixtureModel::class;
                    }
                }
            }

            namespace App\Http\Controllers\Fixtures {
                use App\Services\ModelClassStringService;

                final class DynamicModelClassController
                {
                    public function access(ModelClassStringService $service): void
                    {
                        $class = $service->modelClass();
                        $class::query();
                        new $class();
                    }
                }
            }
            PHP, 'DynamicModelClassController.php');

        self::assertNotSame(0, $analysis['exitCode'], $analysis['output']);
        self::assertSame(
            2,
            substr_count(
                $analysis['output'],
                'Controller App\\Http\\Controllers\\Fixtures\\DynamicModelClassController must not depend directly on model App\\Models\\ForbiddenFixtureModel',
            ),
            $analysis['output'],
        );
    }

    public function test_existing_model_baseline_locations_remain_allowed(): void
    {
        $sourcePath = dirname(__DIR__, 3) . '/app/Http/Controllers/MovieLookupController.php';
        $source = file_get_contents($sourcePath);
        if ($source === false) {
            throw new RuntimeException('Unable to read the MovieLookupController baseline fixture.');
        }

        $analysis = $this->analyseFixture($source, 'MovieLookupController.php');

        self::assertSame(0, $analysis['exitCode'], $analysis['output']);
    }

    /**
     * @return array{exitCode: int|null, output: string}
     */
    private function analyseFixture(string $source, string $fixtureFileName = 'ControllerFixture.php'): array
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
            $fixturePath = $temporaryDirectory . '/' . $fixtureFileName;
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
                $fixturePath,
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

    private function mutateGenreQuery(string $source, string $operation): string
    {
        $lines = explode("\n", $source);
        $queryLine = null;
        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '$labels = Genre::query()') {
                $queryLine = $lineNumber;
                break;
            }
        }

        if ($queryLine === null) {
            throw new RuntimeException('Unable to find the Genre query baseline fixture.');
        }

        if ($operation === 'where') {
            array_splice($lines, $queryLine + 1, 0, ["            ->where('id', '>', 0)"]);
        } elseif ($operation === 'delete') {
            $endLine = null;
            for ($lineNumber = $queryLine + 1, $lineCount = count($lines); $lineNumber < $lineCount; $lineNumber++) {
                if (trim($lines[$lineNumber]) === '->all();') {
                    $endLine = $lineNumber;
                    break;
                }
            }

            if ($endLine === null) {
                throw new RuntimeException('Unable to find the end of the Genre query baseline fixture.');
            }

            array_splice($lines, $queryLine + 1, $endLine - $queryLine, ['            ->delete();']);
        } else {
            throw new RuntimeException('Unsupported Genre query fixture mutation.');
        }

        return implode("\n", $lines);
    }
}

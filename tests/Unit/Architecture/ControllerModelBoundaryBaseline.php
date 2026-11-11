<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPStan\Analyser\Scope;

final class ControllerModelBoundaryBaseline
{
    /**
     * Existing controller/model accesses stay allowed only at these exact
     * source locations. Keep the source text alongside each line so adding a
     * second access to an existing pair cannot silently extend the baseline.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const ALLOWED_USAGES = [
        'App\\Http\\Controllers\\MovieBulkController' => [
            'App\\Models\\Movie' => [
                9 => 'use App\\Models\\Movie;',
                33 => "'status' => \$movie instanceof Movie ? 'found' : 'not_found',",
                34 => "'movie' => \$movie instanceof Movie",
            ],
        ],
        'App\\Http\\Controllers\\MovieLookupController' => [
            'App\\Models\\Movie' => [
                9 => 'use App\\Models\\Movie;',
                21 => 'if (! $movie instanceof Movie) {',
            ],
        ],
        'App\\Http\\Controllers\\MovieRelatedController' => [
            'App\\Models\\Movie' => [
                9 => 'use App\\Models\\Movie;',
                28 => 'if (! $movie instanceof Movie) {',
                32 => "\$items = Cache::remember('javmeta:related:' . \$movie->id, 600, fn(): array => \$this->related(\$movie, \$search));",
                38 => 'private function related(Movie $movie, MovieSearchService $search): array',
                41 => "\$performer = \$movie->performers()->orderBy('performers.id')->first();",
                46 => 'if ($movie->series) {',
                47 => "\$filters[] = ['series' => \$movie->series];",
                49 => 'if ($movie->maker) {',
                50 => "\$filters[] = ['maker' => \$movie->maker];",
                57 => 'if ($item->id !== $movie->id && ! isset($related[$item->id])) {',
                58 => '$related[$item->id] = $item;',
            ],
            'App\\Models\\Performer' => [
                42 => '$name = $performer->name_romaji ?? $performer->name_kanji ?? null;',
            ],
        ],
        'App\\Http\\Controllers\\PerformerController' => [
            'App\\Models\\Performer' => [
                12 => 'use App\\Models\\Performer;',
                76 => 'if (! $performer instanceof Performer) {',
            ],
        ],
        'App\\Http\\Controllers\\StatusController' => [
            'App\\Models\\CrawlQueue' => [
                7 => 'use App\\Models\\CrawlQueue;',
                61 => 'CrawlQueue::STATUS_PENDING => $counts[CrawlQueue::STATUS_PENDING] ?? 0,',
                62 => 'CrawlQueue::STATUS_CLAIMED => $counts[CrawlQueue::STATUS_CLAIMED] ?? 0,',
                63 => 'CrawlQueue::STATUS_DONE => $counts[CrawlQueue::STATUS_DONE] ?? 0,',
                64 => 'CrawlQueue::STATUS_FAILED => $counts[CrawlQueue::STATUS_FAILED] ?? 0,',
            ],
        ],
        'App\\Http\\Controllers\\Operations\\LoginController' => [
            'App\\Models\\User' => [
                16 => "if (\$request->user()?->role === 'super_administrator') {",
                34 => "if (\$request->user()?->role !== 'super_administrator') {",
            ],
        ],
        'App\\Http\\Controllers\\Operations\\NodeController' => [
            'App\\Models\\User' => [
                24 => '$request->user()?->id,',
            ],
        ],
        'App\\Http\\Controllers\\Ui\\UiNotificationTemplateController' => [
            'App\\Models\\User' => [
                63 => '$request->user()?->id,',
                140 => "return response()->json(['data' => \$templates->reset(\$key, \$request->user()?->id)]);",
                158 => '$template = $templates->restore($key, $revision, $request->user()?->id);',
            ],
        ],
        'App\\Http\\Controllers\\Ui\\UiSettingsController' => [
            'App\\Models\\User' => [
                48 => "\$observability->emitOps('setting_changed', ['key' => \$result['key'], 'by' => \$request->user()?->email, 'old' => \$result['old'], 'new' => \$result['new'], 'secret' => \$result['secret']]);",
            ],
        ],
        'App\\Http\\Controllers\\MetaGenreController' => [
            'App\\Models\\Genre' => [
                7 => 'use App\\Models\\Genre;',
                20 => '$labels = Genre::query()',
            ],
        ],
    ];

    /**
     * @param class-string $controller
     */
    public static function allows(Scope $scope, string $controller, string $model, int $line): bool
    {
        $expectedSource = self::ALLOWED_USAGES[$controller][$model][$line] ?? null;
        if ($expectedSource === null) {
            return false;
        }

        $sourceLines = self::sourceLines($scope->getFile());

        return isset($sourceLines[$line - 1]) && trim($sourceLines[$line - 1]) === $expectedSource;
    }

    public static function controllerName(Scope $scope): ?string
    {
        $namespace = $scope->getNamespace();
        if ($namespace === null
            || ($namespace !== 'App\\Http\\Controllers' && ! str_starts_with($namespace, 'App\\Http\\Controllers\\'))) {
            return null;
        }

        $className = $scope->getClassReflection()?->getName();
        if ($className !== null && str_starts_with($className, $namespace . '\\')) {
            return $className;
        }

        $fileName = pathinfo($scope->getFile(), PATHINFO_FILENAME);
        if (! str_ends_with($fileName, 'Controller')) {
            return null;
        }

        return $namespace . '\\' . $fileName;
    }

    public static function isModel(string $className): bool
    {
        $className = ltrim($className, '\\');
        $modelNamespace = 'App\\Models';

        return strcasecmp($className, $modelNamespace) === 0
            || strncasecmp($className, $modelNamespace . '\\', strlen($modelNamespace) + 1) === 0;
    }

    /**
     * @return list<string>
     */
    private static function sourceLines(string $file): array
    {
        static $cache = [];
        if (! array_key_exists($file, $cache)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $cache[$file] = $lines === false ? [] : $lines;
        }

        return $cache[$file];
    }
}

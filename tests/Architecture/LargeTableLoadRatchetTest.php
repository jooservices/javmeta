<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LargeTableLoadRatchetTest extends TestCase
{
    /** @var list<string> */
    private const LARGE_TABLES = [
        'movie_observations', 'movie_genres', 'movie_media', 'performer_name_grams', 'performer_media',
        'crawl_events', 'crawl_fetch_attempts', 'crawl_queue', 'movie_performers', 'movie_sources', 'movie_codes',
        'crawl_runs', 'performer_name_keys', 'performer_sources', 'performers', 'performer_identity_reviews', 'movies',
    ];

    /** @var list<string> */
    private const LARGE_MODELS = [
        'MovieObservation', 'MovieGenre', 'MovieMedia', 'PerformerNameGram', 'PerformerMedia', 'CrawlEvent',
        'CrawlFetchAttempt', 'CrawlQueue', 'MoviePerformer', 'MovieSource', 'MovieCode', 'CrawlRun',
        'PerformerNameKey', 'PerformerSource', 'PerformerIdentityReview', 'Movie', 'Performer',
    ];

    public function test_large_table_loads_do_not_exceed_the_reviewed_baseline(): void
    {
        /** @var list<string> $baseline */
        $baseline = require __DIR__ . '/large-table-baseline.php';
        $findings = $this->unboundedLoads();
        $newFindings = array_values(array_diff($findings, $baseline));
        $removedFindings = array_values(array_diff($baseline, $findings));

        self::assertSame([], $newFindings, "New unbounded large-table loads:\n" . implode("\n", $newFindings));
        self::assertSame([], $removedFindings, "Remove fixed entries from large-table-baseline.php:\n" . implode("\n", $removedFindings));
    }

    /** @return list<string> */
    private function unboundedLoads(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $directories = ['/Http', '/Services', '/Repositories', '/Console'];
        $findings = [];

        foreach ($directories as $directory) {
            $path = $root . $directory;
            if (! is_dir($path)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if (! is_string($source)) {
                    continue;
                }
                $relativePath = substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
                $parser = (new ParserFactory())->createForNewestSupportedVersion();
                $ast = $parser->parse($source);
                if ($ast === null) {
                    continue;
                }
                $visitor = new LargeTableLoadVisitor($relativePath, self::LARGE_TABLES, self::LARGE_MODELS);
                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                array_push($findings, ...$visitor->findings());
            }
        }

        sort($findings);

        return array_values(array_unique($findings));
    }
}

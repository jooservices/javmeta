<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Support\Sources\SourceParityChecker;
use JOOservices\CrawlerX\Registry\FileAdapterManifestRegistry;
use Tests\TestCase;

/**
 * Fails when crawlerx gains, loses or changes an adapter without javmeta following.
 */
final class SourceParityTest extends TestCase
{
    /**
     * Documented, deliberate gaps. Each needs a reason; a gap that is no longer a gap fails the build.
     *
     * @var array<string, array<string, string>>
     */
    private const KNOWN_GAPS = [
        'minnanoav' => [SourceParityChecker::MOVIE => 'Movie capability exists in crawlerx; javmeta seeds the performer index only (README site matrix).'],
    ];

    public function test_every_crawlerx_adapter_has_a_matching_javmeta_source_and_capabilities(): void
    {
        $registry = new FileAdapterManifestRegistry();
        $capabilities = [];
        foreach ($registry->slugs() as $slug) {
            $capabilities[$slug] = array_values($registry->get($slug)->capabilities ?? []);
        }

        $problems = (new SourceParityChecker())->problems(
            $capabilities,
            (array) config('javmeta_sources.sources'),
            self::KNOWN_GAPS,
        );

        self::assertSame([], $problems, "Source parity problems:\n" . implode("\n", $problems));
        self::assertGreaterThanOrEqual(25, count($capabilities));
    }

    public function test_the_readme_site_matrix_lists_exactly_the_configured_sources(): void
    {
        $readme = (string) file_get_contents(base_path('README.md'));
        // Only the table right under the "Site capability matrix" heading, up to the next heading.
        self::assertSame(1, preg_match('/^## Site capability matrix\R(.*?)(?=^## )/ms', $readme, $section), 'README is missing the "Site capability matrix" section.');
        preg_match_all('/^\| `([a-z0-9_]+)` \|/m', $section[1], $matches);

        $documented = array_values(array_unique($matches[1]));
        $configured = array_keys((array) config('javmeta_sources.sources'));
        sort($documented);
        sort($configured);

        self::assertSame($configured, $documented, 'README "Site capability matrix" must list exactly the sources in config/javmeta_sources.php.');
    }
}

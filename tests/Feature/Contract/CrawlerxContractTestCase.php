<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsCrawlerxFixtures;
use Tests\TestCase;

/**
 * Real crawlerx inside the javmeta jobs, sqlite in-memory, HTTP faked.
 * See Tests\Support\RunsCrawlerxFixtures.
 */
abstract class CrawlerxContractTestCase extends TestCase
{
    use RefreshDatabase;
    use RunsCrawlerxFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCrawlerxHttp();
    }

    protected function tearDown(): void
    {
        $this->stopFakingCrawlerxHttp();

        parent::tearDown();
    }
}

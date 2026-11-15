<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Downloads;

use App\Support\Downloads\DownloadCapableSources;
use Tests\TestCase;

final class DownloadCapableSourcesTest extends TestCase
{
    public function test_it_lists_only_sources_marked_offers_downloads(): void
    {
        $slugs = (new DownloadCapableSources())->slugs();

        self::assertSame(['141jav', 'avfan', 'ffjav', 'javbus', 'javdb', 'onejav'], $slugs);
        self::assertTrue((new DownloadCapableSources())->offers('onejav'));
        self::assertFalse((new DownloadCapableSources())->offers('javbtc'));
        self::assertFalse((new DownloadCapableSources())->offers('missav'));
    }

    public function test_it_returns_empty_when_sources_config_is_not_an_array(): void
    {
        config(['javmeta_sources.sources' => 'broken']);

        self::assertSame([], (new DownloadCapableSources())->slugs());
    }
}

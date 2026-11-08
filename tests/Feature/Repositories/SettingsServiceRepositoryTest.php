<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Repositories\SettingsServiceRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SettingsServiceRepositoryTest extends TestCase
{
    public function test_missing_source_table_returns_an_empty_list(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('sources')->andReturn(false);

        self::assertSame([], app(SettingsServiceRepository::class)->sourceSlugs());
    }
}

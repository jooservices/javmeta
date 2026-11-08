<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** staging:readiness text output (the JSON output and the individual checks are covered in StagingReadinessTest). */
final class HealthCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_output_lists_every_check_and_exits_non_zero_when_staging_is_not_ready(): void
    {
        $this->travelTo('2026-11-02T12:34:00Z');

        self::assertSame(1, Artisan::call('staging:readiness', ['--no-record' => true]));

        $output = Artisan::output();
        self::assertMatchesRegularExpression('/^\[FAIL\] newest_movie: There are no movies\. -> /m', $output);
        self::assertMatchesRegularExpression('/^\[(PASS|WARN|FAIL)\] \w+: /m', $output);
        self::assertStringContainsString('Staging is NOT READY', $output);
        self::assertStringNotContainsString('Staging is READY', $output);
    }
}

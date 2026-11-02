<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class SourceGapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_gap_command_lists_definition_override_effective_and_adaptive_values_read_only(): void
    {
        config()->set('javmeta_sources.sources', [
            'slow-site' => [
                'gap_seconds_min' => 5,
                'gap_seconds_default' => 10,
                'gap_seconds_max' => 60,
            ],
        ]);
        config()->set('javmeta_queues.source_gap_overrides', [
            'slow-site' => ['min' => 20, 'default' => 30, 'max' => 40],
        ]);
        $source = Source::factory()->create([
            'slug' => 'slow-site',
            'gap_seconds_current' => 55,
            'gap_seconds_min' => 5,
            'gap_seconds_default' => 10,
            'gap_seconds_max' => 60,
        ]);

        $output = new BufferedOutput();
        self::assertSame(0, Artisan::call('crawler:source-gap', ['slug' => 'slow-site', '--json' => true], $output));
        $display = $output->fetch();
        self::assertStringContainsString('"definition"', $display);
        self::assertStringContainsString('"override"', $display);
        self::assertStringContainsString('"effective"', $display);
        self::assertStringContainsString('"current_adaptive"', $display);
        self::assertStringContainsString('"min": 5', $display);
        self::assertStringContainsString('"default": 10', $display);
        self::assertStringContainsString('"min": 20', $display);
        self::assertStringContainsString('"default": 30', $display);
        self::assertStringContainsString('"current_adaptive": 40', $display);

        self::assertSame(55.0, (float) $source->fresh()->gap_seconds_current);
    }

    public function test_source_gap_command_rejects_an_unknown_slug(): void
    {
        $this->artisan('crawler:source-gap', ['slug' => 'missing'])
            ->expectsOutputToContain('Unknown source [missing].')
            ->assertFailed();
    }
}

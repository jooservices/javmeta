<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlEvent;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourceToggleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_switches_a_source_and_records_who_did_it(): void
    {
        Source::factory()->create(['slug' => 'demo', 'enabled' => true]);

        $this->artisan('crawler:source-toggle', ['slug' => 'demo', 'state' => 'off', '--by' => 'tester'])->assertSuccessful();

        $this->assertFalse((bool) Source::query()->find('demo')?->enabled);
        $event = CrawlEvent::query()->where('kind', CrawlEvent::KIND_SOURCE_TOGGLED)->firstOrFail();
        $this->assertSame('demo', $event->source_slug);
        $this->assertSame(['from' => true, 'to' => false, 'by' => 'tester'], $event->detail);

        $this->artisan('crawler:source-toggle', ['slug' => 'demo', 'state' => 'on'])->assertSuccessful();
        $this->assertTrue((bool) Source::query()->find('demo')?->enabled);
        $this->assertSame(2, CrawlEvent::query()->where('kind', CrawlEvent::KIND_SOURCE_TOGGLED)->count());
    }

    public function test_toggle_to_the_same_state_writes_no_event(): void
    {
        Source::factory()->create(['slug' => 'demo', 'enabled' => true]);

        $this->artisan('crawler:source-toggle', ['slug' => 'demo', 'state' => 'on'])->assertSuccessful();

        $this->assertSame(0, CrawlEvent::query()->count());
    }

    public function test_toggle_rejects_an_unknown_source_and_a_bad_state(): void
    {
        Source::factory()->create(['slug' => 'demo']);

        $this->artisan('crawler:source-toggle', ['slug' => 'missing', 'state' => 'on'])->assertFailed();
        $this->artisan('crawler:source-toggle', ['slug' => 'demo', 'state' => 'maybe'])->assertExitCode(2);
    }

    public function test_sources_command_lists_the_database_switch(): void
    {
        Source::factory()->create(['slug' => 'on-site', 'enabled' => true]);
        Source::factory()->create(['slug' => 'off-site', 'enabled' => false]);

        $this->artisan('crawler:sources')->expectsOutputToContain('off-site')->assertSuccessful();
    }
}

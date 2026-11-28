<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RetireBacklogDepthAlertMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_open_alert_of_the_retired_rule_is_closed_and_its_template_removed(): void
    {
        $this->openEvent('backlog_depth:javbus');
        $this->openEvent('backlog_depth:ffjav');
        $this->openEvent('backlog_age:javbus');
        $this->openEvent('dispatch_age');
        DB::table('notification_templates')->insert([
            'key' => 'crawl.backlog_by_source_high', 'name' => 'old', 'usage' => 'old', 'channel' => 'telegram',
            'title_template' => 't', 'body_template' => 'b', 'variables' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_11_28_000001_retire_backlog_depth_alert.php'))->up();

        $closed = DB::table('alert_events')->where('rule', 'like', 'backlog_depth:%')->get();
        self::assertCount(2, $closed);
        foreach ($closed as $event) {
            self::assertNotNull($event->resolved_at);
            self::assertNotNull($event->recovery_completed_at, 'No recovery notice is owed for a rule that no longer exists.');
        }
        self::assertNull(DB::table('alert_events')->where('rule', 'backlog_age:javbus')->value('resolved_at'));
        self::assertNull(DB::table('alert_events')->where('rule', 'dispatch_age')->value('resolved_at'));
        self::assertSame(0, DB::table('notification_templates')->where('key', 'crawl.backlog_by_source_high')->count());
    }

    public function test_the_migration_can_run_again_and_can_be_rolled_back(): void
    {
        $migration = require database_path('migrations/2026_11_28_000001_retire_backlog_depth_alert.php');
        $migration->up();
        $migration->up();
        $migration->down();

        self::assertSame(0, DB::table('alert_events')->where('rule', 'like', 'backlog_depth:%')->count());
    }

    private function openEvent(string $rule): void
    {
        DB::table('alert_events')->insert(['rule' => $rule, 'severity' => 'warning', 'value' => 1, 'threshold' => 10, 'started_at' => now()]);
    }
}

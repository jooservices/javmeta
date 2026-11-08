<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NotificationPolicyMigrationTest extends TestCase
{
    public function test_rollback_preserves_daily_type_while_restoring_the_original_unique_index(): void
    {
        $connection = 'notification_policy_rollback_test';
        $originalConnection = (string) config('database.default');
        config(['database.connections.' . $connection => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($connection);

        try {
            $schema = Schema::connection($connection);
            foreach (['notification_templates', 'notification_template_revisions'] as $tableName) {
                $schema->create($tableName, static function (Blueprint $table): void {
                    $table->id();
                    $table->string('tier', 16)->default('info');
                    $table->unsignedInteger('cooldown_minutes')->default(0);
                    $table->unsignedInteger('escalation_minutes')->nullable();
                    $table->boolean('include_in_periodic_report')->default(false);
                    $table->boolean('include_in_daily_report')->default(false);
                    $table->boolean('send_resolved')->default(false);
                });
            }
            $schema->create('report_snapshots', static function (Blueprint $table): void {
                $table->id();
                $table->string('scheduled_for')->nullable();
                $table->string('report_type', 16)->default('periodic');
                $table->unique(['report_type', 'scheduled_for'], 'report_snapshots_type_slot_unique');
            });
            $scheduledFor = '2026-11-03 08:00:00';
            DB::connection($connection)->table('report_snapshots')->insert([
                ['scheduled_for' => $scheduledFor, 'report_type' => 'periodic'],
                ['scheduled_for' => $scheduledFor, 'report_type' => 'daily'],
            ]);

            config(['database.default' => $connection]);
            DB::setDefaultConnection($connection);
            $migration = require database_path('migrations/2026_11_03_000005_add_notification_policy.php');
            $migration->down();

            self::assertTrue(Schema::hasColumn('report_snapshots', 'report_type'));
            self::assertSame(1, DB::table('report_snapshots')->whereNull('scheduled_for')->count());
            self::assertSame(1, DB::table('report_snapshots')->where('scheduled_for', $scheduledFor)->count());
            self::assertSame(1, DB::table('report_snapshots')->where('report_type', 'daily')->whereNull('scheduled_for')->count());
            try {
                DB::table('report_snapshots')->insert(['scheduled_for' => $scheduledFor]);
                self::fail('Rollback did not restore the scheduled_for uniqueness constraint.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        } finally {
            config(['database.default' => $originalConnection]);
            DB::setDefaultConnection($originalConnection);
            DB::purge($connection);
        }
    }
}

<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        $this->withLockTimeout(function (): void {
            foreach (['notification_templates', 'notification_template_revisions'] as $tableName) {
                Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                    if (! Schema::hasColumn($tableName, 'tier')) {
                        $table->string('tier', 16)->default('info');
                    }
                    if (! Schema::hasColumn($tableName, 'cooldown_minutes')) {
                        $table->unsignedInteger('cooldown_minutes')->default(0);
                    }
                    if (! Schema::hasColumn($tableName, 'escalation_minutes')) {
                        $table->unsignedInteger('escalation_minutes')->nullable();
                    }
                    if (! Schema::hasColumn($tableName, 'include_in_periodic_report')) {
                        $table->boolean('include_in_periodic_report')->default(false);
                    }
                    if (! Schema::hasColumn($tableName, 'include_in_daily_report')) {
                        $table->boolean('include_in_daily_report')->default(false);
                    }
                    if (! Schema::hasColumn($tableName, 'send_resolved')) {
                        $table->boolean('send_resolved')->default(false);
                    }
                });
            }

            if (! Schema::hasTable('notification_policy_events')) {
                Schema::create('notification_policy_events', function (Blueprint $table): void {
                    $table->id();
                    $table->string('notification_key', 160);
                    $table->string('cause_hash', 64);
                    $table->timestampTz('first_seen_at');
                    $table->timestampTz('last_seen_at');
                    $table->timestampTz('last_sent_at')->nullable();
                    $table->timestampTz('critical_sent_at')->nullable();
                    $table->timestampTz('notice_sent_at')->nullable();
                    $table->timestampTz('escalated_at')->nullable();
                    $table->timestampTz('resolved_at')->nullable();
                    $table->timestampTz('reserved_at')->nullable();
                    $table->string('reserved_tier', 16)->nullable();
                    $table->decimal('value', 18, 4)->nullable();
                    $table->decimal('threshold', 18, 4)->nullable();
                    $table->json('context')->nullable();
                    $table->timestampsTz();
                    if (DB::getDriverName() !== 'pgsql') {
                        $table->index('notification_key');
                        $table->unique(['notification_key', 'cause_hash'], 'notification_policy_events_cause_unique');
                        $table->index(['resolved_at', 'notification_key']);
                    }
                });
            }

            if (! Schema::hasTable('notification_policy_metric_counters')) {
                Schema::create('notification_policy_metric_counters', function (Blueprint $table): void {
                    $table->id();
                    $table->string('counter_key', 255);
                    $table->string('metric', 96);
                    $table->string('tier', 16)->nullable();
                    $table->string('notification_key', 160)->nullable();
                    $table->string('reason', 48)->nullable();
                    $table->unsignedBigInteger('value')->default(0);
                    $table->timestampTz('updated_at');
                    if (DB::getDriverName() !== 'pgsql') {
                        $table->unique('counter_key');
                        $table->index(['metric', 'notification_key'], 'notification_policy_metrics_lookup_idx');
                    }
                });
            }

            if (Schema::hasTable('report_snapshots') && ! Schema::hasColumn('report_snapshots', 'report_type')) {
                Schema::table('report_snapshots', static function (Blueprint $table): void {
                    $table->string('report_type', 16)->default('periodic');
                });
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createConcurrentIndex(
                'notification_policy_events_notification_key_index',
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS notification_policy_events_notification_key_index ON notification_policy_events (notification_key)',
            );
            $this->createConcurrentIndex(
                'notification_policy_events_cause_unique',
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS notification_policy_events_cause_unique ON notification_policy_events (notification_key, cause_hash)',
            );
            $this->createConcurrentIndex(
                'notification_policy_events_resolved_at_notification_key_index',
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS notification_policy_events_resolved_at_notification_key_index ON notification_policy_events (resolved_at, notification_key)',
            );
            $this->createConcurrentIndex(
                'notification_policy_metric_counters_counter_key_unique',
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS notification_policy_metric_counters_counter_key_unique ON notification_policy_metric_counters (counter_key)',
            );
            $this->createConcurrentIndex(
                'notification_policy_metrics_lookup_idx',
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS notification_policy_metrics_lookup_idx ON notification_policy_metric_counters (metric, notification_key)',
            );

            if (Schema::hasTable('report_snapshots')) {
                $this->createConcurrentIndex(
                    'report_snapshots_type_slot_unique',
                    'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS report_snapshots_type_slot_unique ON report_snapshots (report_type, scheduled_for)',
                );
                $this->withLockTimeout(static fn(): bool => DB::statement('ALTER TABLE report_snapshots DROP CONSTRAINT IF EXISTS report_snapshots_scheduled_for_unique'));
                $this->withLockTimeout(static fn(): bool => DB::statement('DROP INDEX CONCURRENTLY IF EXISTS report_snapshots_scheduled_for_unique'));
            }
        } elseif (Schema::hasTable('report_snapshots')) {
            Schema::table('report_snapshots', static function (Blueprint $table): void {
                $table->dropUnique('report_snapshots_scheduled_for_unique');
                $table->unique(['report_type', 'scheduled_for'], 'report_snapshots_type_slot_unique');
            });
        }

        foreach (app(NotificationRegistry::class)->all() as $key => $definition) {
            DB::table('notification_templates')->where('key', $key)->update([
                'tier' => $definition['tier'],
                'cooldown_minutes' => $definition['cooldown_minutes'],
                'escalation_minutes' => $definition['escalation_minutes'],
                'include_in_periodic_report' => $definition['include_in_periodic_report'],
                'include_in_daily_report' => $definition['include_in_daily_report'],
                'send_resolved' => $definition['send_resolved'],
            ]);

            $templateId = DB::table('notification_templates')->where('key', $key)->value('id');
            if ($templateId !== null) {
                DB::table('notification_template_revisions')
                    ->where('notification_template_id', $templateId)
                    ->update([
                        'tier' => $definition['tier'],
                        'cooldown_minutes' => $definition['cooldown_minutes'],
                        'escalation_minutes' => $definition['escalation_minutes'],
                        'include_in_periodic_report' => $definition['include_in_periodic_report'],
                        'include_in_daily_report' => $definition['include_in_daily_report'],
                        'send_resolved' => $definition['send_resolved'],
                    ]);
            }
        }
        app(NotificationTemplateService::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_policy_metric_counters');
        Schema::dropIfExists('notification_policy_events');

        if (Schema::hasTable('report_snapshots') && Schema::hasColumn('report_snapshots', 'report_type')) {
            DB::table('report_snapshots')->where('report_type', 'daily')->update(['scheduled_for' => null]);
            if (DB::getDriverName() === 'pgsql') {
                $this->withLockTimeout(static fn(): bool => DB::statement('DROP INDEX CONCURRENTLY IF EXISTS report_snapshots_type_slot_unique'));
                $this->withLockTimeout(static fn(): bool => DB::statement('ALTER TABLE report_snapshots DROP COLUMN IF EXISTS report_type'));
                $this->createConcurrentIndex(
                    'report_snapshots_scheduled_for_unique',
                    'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS report_snapshots_scheduled_for_unique ON report_snapshots (scheduled_for)',
                );
            } else {
                Schema::table('report_snapshots', static function (Blueprint $table): void {
                    $table->dropUnique('report_snapshots_type_slot_unique');
                    $table->dropColumn('report_type');
                    $table->unique('scheduled_for', 'report_snapshots_scheduled_for_unique');
                });
            }
        }

        foreach (['notification_template_revisions', 'notification_templates'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                $this->withLockTimeout(static function () use ($tableName): void {
                    foreach (['tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved'] as $column) {
                        DB::statement('ALTER TABLE ' . $tableName . ' DROP COLUMN IF EXISTS ' . $column);
                    }
                });
            } else {
                Schema::table($tableName, static function (Blueprint $table) use ($tableName): void {
                    $columns = array_values(array_filter(
                        ['tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved'],
                        static fn(string $column): bool => Schema::hasColumn($tableName, $column),
                    ));
                    if ($columns !== []) {
                        $table->dropColumn($columns);
                    }
                });
            }
        }
    }

    private function createConcurrentIndex(string $name, string $statement): void
    {
        $existing = DB::selectOne(
            'SELECT CASE WHEN NOT i.indisvalid THEN 1 ELSE 0 END AS invalid FROM pg_class AS c JOIN pg_index AS i ON i.indexrelid = c.oid WHERE c.oid = to_regclass(?)',
            [$name],
        );

        if ((int) ($existing->invalid ?? 0) === 1) {
            $this->withLockTimeout(static fn(): bool => DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $name));
        }

        $this->withLockTimeout(static fn(): bool => DB::statement($statement));
    }

    private function withLockTimeout(callable $operation): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $operation();
        }

        DB::statement("SET lock_timeout = '3s'");
        try {
            return $operation();
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};

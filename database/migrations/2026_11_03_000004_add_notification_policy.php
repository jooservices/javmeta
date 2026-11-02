<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_templates', static function (Blueprint $table): void {
            $table->string('tier', 16)->default('info');
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->unsignedInteger('escalation_minutes')->nullable();
            $table->boolean('include_in_periodic_report')->default(false);
            $table->boolean('include_in_daily_report')->default(false);
            $table->boolean('send_resolved')->default(false);
        });
        Schema::table('notification_template_revisions', static function (Blueprint $table): void {
            $table->string('tier', 16)->default('info');
            $table->unsignedInteger('cooldown_minutes')->default(0);
            $table->unsignedInteger('escalation_minutes')->nullable();
            $table->boolean('include_in_periodic_report')->default(false);
            $table->boolean('include_in_daily_report')->default(false);
            $table->boolean('send_resolved')->default(false);
        });

        Schema::create('notification_policy_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('notification_key', 160)->index();
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
            $table->unique(['notification_key', 'cause_hash'], 'notification_policy_events_cause_unique');
            $table->index(['resolved_at', 'notification_key']);
        });

        Schema::create('notification_policy_metric_counters', static function (Blueprint $table): void {
            $table->id();
            $table->string('counter_key', 255)->unique();
            $table->string('metric', 96);
            $table->string('tier', 16)->nullable();
            $table->string('notification_key', 160)->nullable();
            $table->string('reason', 48)->nullable();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestampTz('updated_at');
            $table->index(['metric', 'notification_key'], 'notification_policy_metrics_lookup_idx');
        });

        if (Schema::hasTable('report_snapshots')) {
            Schema::table('report_snapshots', static function (Blueprint $table): void {
                $table->string('report_type', 16)->default('periodic');
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
        }
        app(NotificationTemplateService::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_policy_metric_counters');
        Schema::dropIfExists('notification_policy_events');
        if (Schema::hasTable('report_snapshots') && Schema::hasColumn('report_snapshots', 'report_type')) {
            Schema::table('report_snapshots', static function (Blueprint $table): void {
                $table->dropUnique('report_snapshots_type_slot_unique');
                $table->dropColumn('report_type');
                $table->unique('scheduled_for', 'report_snapshots_scheduled_for_unique');
            });
        }
        Schema::table('notification_template_revisions', static function (Blueprint $table): void {
            $table->dropColumn(['tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved']);
        });
        Schema::table('notification_templates', static function (Blueprint $table): void {
            $table->dropColumn(['tier', 'cooldown_minutes', 'escalation_minutes', 'include_in_periodic_report', 'include_in_daily_report', 'send_resolved']);
        });
    }
};

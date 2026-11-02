<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('alert_events', function (Blueprint $table): void {
            $table->id();
            $table->string('rule', 150);
            $table->string('severity', 20);
            $table->decimal('value', 12, 2);
            $table->decimal('threshold', 12, 2);
            $table->timestampTz('started_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->index(['resolved_at', 'started_at'], 'alert_events_active_history_idx');
            $table->index(['rule', 'resolved_at'], 'alert_events_rule_active_idx');
            $table->index('started_at', 'alert_events_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_events');
    }
};

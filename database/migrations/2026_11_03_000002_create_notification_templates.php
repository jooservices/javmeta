<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_templates', static function (Blueprint $table): void {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('name', 160);
            $table->string('usage', 255);
            $table->string('channel', 24)->index();
            $table->text('title_template');
            $table->longText('body_template');
            $table->boolean('enabled')->default(true);
            $table->string('default_severity', 16)->default('warning');
            $table->json('variables');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('sent_count')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notification_template_revisions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_template_id')->constrained('notification_templates')->cascadeOnDelete();
            $table->text('title_template');
            $table->longText('body_template');
            $table->boolean('enabled');
            $table->string('default_severity', 16);
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['notification_template_id', 'created_at']);
        });

        app(NotificationTemplateService::class)->seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_template_revisions');
        Schema::dropIfExists('notification_templates');
    }
};

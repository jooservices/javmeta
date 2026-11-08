<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('follow_notifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('movie_code', 64);
            $table->text('reason');
            $table->string('channel', 24)->default('telegram');
            $table->timestampTz('sent_at');

            // One message per movie and channel, ever: a follow that matches again never sends twice.
            $table->unique(['user_id', 'movie_code', 'channel']);
            $table->index(['channel', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_notifications');
    }
};

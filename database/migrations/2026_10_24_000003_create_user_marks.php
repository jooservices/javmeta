<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_marks', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // favourite, watch_later, seen on movies; follow on performers, makers and series; feed_seen keeps the last visit of the feed.
            $table->string('kind', 24);
            $table->string('subject', 24);
            $table->text('key');
            $table->timestampTz('created_at');

            $table->unique(['user_id', 'kind', 'subject', 'key']);
            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_marks');
    }
};

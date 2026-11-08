<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('performers', function (Blueprint $table): void {
            $table->string('blood_rh', 1)->nullable();
        });

        Schema::create('normalization_rejections', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 32);
            $table->string('field', 64);
            $table->string('source', 100);
            $table->unsignedBigInteger('rejected_total')->default(0);
            $table->timestamps();
            $table->unique(['entity', 'field', 'source'], 'normalization_rejections_labels_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalization_rejections');
        Schema::table('performers', function (Blueprint $table): void {
            $table->dropColumn('blood_rh');
        });
    }
};

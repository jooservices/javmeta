<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NormalizationReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_prints_empty_state_and_rejection_rows_in_label_order(): void
    {
        $this->artisan('performers:normalization-report')
            ->expectsOutputToContain('No rejected values have been recorded.')
            ->assertSuccessful();

        DB::table('normalization_rejections')->insert([
            ['entity' => 'performer', 'field' => 'height_cm', 'source' => 'javbus', 'rejected_total' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['entity' => 'performer', 'field' => 'birth_date', 'source' => 'javdb', 'rejected_total' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('performers:normalization-report')
            ->expectsOutputToContain('javmeta_normalize_rejected_total{entity,field,source}')
            ->expectsOutputToContain('javmeta_normalize_rejected_total{entity="performer",field="birth_date",source="javdb"} 2')
            ->expectsOutputToContain('javmeta_normalize_rejected_total{entity="performer",field="height_cm",source="javbus"} 3')
            ->assertSuccessful();
    }
}

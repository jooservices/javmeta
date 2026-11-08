<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\Movie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Refusal branches of dataset:wipe (the happy path lives in SampleDatasetTest). */
final class DatasetCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_wipe_only_knows_the_sample_dataset(): void
    {
        $this->artisan('dataset:wipe', ['name' => 'production'])
            ->expectsOutputToContain('Only the "sample" dataset exists.')
            ->assertFailed();
    }

    public function test_wipe_is_refused_outside_testing_when_the_database_is_not_allow_listed(): void
    {
        Movie::factory()->create();
        $this->app->detectEnvironment(static fn(): string => 'local');
        config(['javmeta_dataset.allowed_databases' => ['some_other_database']]);

        $this->artisan('dataset:wipe', ['name' => 'sample', '--force' => true])
            ->expectsOutputToContain('Sample dataset refused: the effective database is not allow-listed.')
            ->assertFailed();

        self::assertSame(1, Movie::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Source> */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['slug' => fake()->unique()->slug(2), 'name' => fake()->company(), 'base_url' => fake()->url(), 'enabled' => true, 'priority' => fake()->numberBetween(1, 100), 'needs_proxy' => false, 'gap_seconds_default' => fake()->randomFloat(2, 10, 60), 'gap_seconds_min' => fake()->randomFloat(2, 5, 10), 'gap_seconds_max' => fake()->randomFloat(2, 60, 120), 'gap_seconds_current' => fake()->randomFloat(2, 10, 60), 'last_success_at' => null, 'last_error_at' => null, 'last_error' => null];
    }
}

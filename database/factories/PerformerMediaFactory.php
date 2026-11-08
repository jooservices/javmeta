<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Performer;
use App\Models\PerformerMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PerformerMedia> */
class PerformerMediaFactory extends Factory
{
    protected $model = PerformerMedia::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'performer_id' => Performer::factory(),
            'kind' => fake()->randomElement([PerformerMedia::KIND_IMAGE, PerformerMedia::KIND_GALLERY]),
            'url' => fake()->unique()->url(),
            'meta' => null,
            'source_slug' => fake()->slug(2),
            'crawled_at' => now(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Models\Performer;
use App\Repositories\TelegramBotServiceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TelegramBotServiceRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_performer_lookup_matches_romanized_and_kanji_names(): void
    {
        $performer = Performer::factory()->create([
            'name_romaji' => 'Aoi Tsukasa',
            'name_kanji' => '葵つかさ',
        ]);
        $repository = app(TelegramBotServiceRepository::class);

        self::assertSame((int) $performer->id, $repository->performerId('AOI TSUKASA'));
        self::assertSame((int) $performer->id, $repository->performerId('葵つかさ'));
        self::assertNull($repository->performerId('unknown performer'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Models\Movie;
use App\Models\Performer;
use App\Repositories\QueryInterpreterServiceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class QueryInterpreterServiceRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_facet_lookups_return_known_values(): void
    {
        Movie::factory()->create(['maker' => 'Moodyz', 'series' => 'Night Shift']);
        DB::table('genres')->insert(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        Performer::factory()->create([
            'name_romaji' => 'Aoi Tsukasa',
            'name_kanji' => '葵つかさ',
            'name_kana' => 'あおいつかさ',
            'blood_type' => 'A',
            'cup' => 'D',
            'location' => 'Tokyo',
        ]);
        $repository = app(QueryInterpreterServiceRepository::class);

        self::assertSame('Moodyz', $repository->knownMovieValue('maker', 'MOODYZ'));
        self::assertSame('Night Shift', $repository->knownMovieValue('series', 'night shift'));
        self::assertSame('Drama', $repository->knownMovieValue('genre', 'drama'));
        self::assertNull($repository->knownMovieValue('label', 'unknown'));
        self::assertSame('Aoi Tsukasa', $repository->knownPerformer('aoi tsukasa'));
        self::assertSame('A', $repository->knownFacet('blood_type', 'a'));
        self::assertNull($repository->knownFacet('unsupported', 'value'));
        self::assertSame(['D'], $repository->facetNames('cup'));
        self::assertSame([], $repository->facetNames('unsupported'));
        self::assertSame(['Moodyz'], $repository->catalogNames('makers'));
        self::assertSame(['Night Shift'], $repository->catalogNames('series'));
        self::assertSame(['Drama'], $repository->catalogNames('genres'));
        self::assertSame([], $repository->catalogNames('unsupported'));
        self::assertSame('Aoi Tsukasa', $repository->performerNameIn(['aoi tsukasa']));
    }

    public function test_performer_lookup_returns_null_when_no_name_is_available(): void
    {
        $repository = app(QueryInterpreterServiceRepository::class);

        self::assertNull($repository->knownPerformer('missing'));
        self::assertNull($repository->knownFacet('blood_type', 'unknown'));
        self::assertNull($repository->performerNameIn(['missing']));

        Performer::factory()->create(['name_romaji' => null, 'name_kanji' => null, 'name_kana' => null]);

        self::assertNull($repository->knownPerformer('empty'));
    }
}

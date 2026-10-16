<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Data\Crawl\PerformerDraft;
use App\Http\Resources\PerformerResource;
use App\Http\Resources\PerformerSummaryResource;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerMedia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Tests\TestCase;

final class PerformerResourceTest extends TestCase
{
    public function test_it_exposes_slim_keys_only_for_a_performer_summary(): void
    {
        $performer = $this->performer();
        $performer->setRelation('aliases', new Collection([
            new PerformerAlias(['alias' => 'Aoi A.']),
        ]));

        $payload = (new PerformerSummaryResource($performer))->toArray(Request::create('/api/v1/performers'));

        self::assertSame([
            'uuid', 'id', 'name_romaji', 'name_kanji', 'name_kana', 'aliases', 'image_url', 'profile_url',
        ], array_keys($payload));
        self::assertSame(['Aoi A.'], $payload['aliases']);
        self::assertArrayNotHasKey('bust', $payload);
        self::assertArrayNotHasKey('bio_text', $payload);
        self::assertArrayNotHasKey('linked_title_count', $payload);
    }

    public function test_it_exposes_the_full_profile_with_loaded_relations_and_dates(): void
    {
        $performer = $this->performer([
            'birth_date' => '1990-01-02',
            'debut_date' => '2010-03-04',
            'attrs' => ['note' => 'profile'],
            'movies_count' => 2,
        ]);
        $performer->setRelation('aliases', new Collection([
            new PerformerAlias(['alias' => 'Aoi A.']),
        ]));
        $performer->setRelation('media', new Collection());

        $payload = (new PerformerResource($performer))->toArray(Request::create('/api/v1/performers/42'));

        self::assertSame('1990-01-02', $payload['birth_date']);
        self::assertSame('2010-03-04', $payload['debut_date']);
        self::assertSame(['Aoi A.'], $payload['aliases']);
        self::assertSame(['note' => 'profile'], $payload['attrs']);
        self::assertSame(2, $payload['linked_title_count']);
        self::assertSame(['images' => [], 'galleries' => []], $payload['photos']);
    }

    public function test_it_formats_images_and_orders_gallery_photos_by_position(): void
    {
        $performer = $this->performer();
        $performer->setRelation('media', new Collection([
            new PerformerMedia([
                'kind' => PerformerMedia::KIND_IMAGE,
                'url' => 'https://images.test/profile.jpg',
                'meta' => ['thumbnail_url' => 'https://images.test/thumb.jpg'],
                'source_slug' => 'javdb',
                'crawled_at' => '2026-10-01T00:00:00+00:00',
            ]),
            new PerformerMedia([
                'kind' => PerformerMedia::KIND_GALLERY,
                'url' => 'https://images.test/gallery-2.jpg',
                'meta' => ['gallery_id' => 'set-1', 'gallery_title' => 'Set 1', 'position' => 2],
                'source_slug' => 'javdb',
            ]),
            new PerformerMedia([
                'kind' => PerformerMedia::KIND_GALLERY,
                'url' => 'https://images.test/gallery-1.jpg',
                'meta' => ['gallery_id' => 'set-1', 'gallery_title' => 'Set 1', 'position' => 1],
                'source_slug' => 'javdb',
            ]),
        ]));

        $payload = (new PerformerResource($performer))->toArray(Request::create('/api/v1/performers/42'));

        self::assertSame('https://images.test/profile.jpg', $payload['photos']['images'][0]['url']);
        self::assertSame('https://images.test/thumb.jpg', $payload['photos']['images'][0]['thumbnail_url']);
        self::assertSame('set-1', $payload['photos']['galleries'][0]['id']);
        self::assertSame('Set 1', $payload['photos']['galleries'][0]['title']);
        self::assertSame([
            'https://images.test/gallery-1.jpg',
            'https://images.test/gallery-2.jpg',
        ], array_column($payload['photos']['galleries'][0]['images'], 'url'));
    }

    public function test_it_maps_performer_drafts_to_summary_and_detail_payloads(): void
    {
        $draft = new PerformerDraft(
            'javdb',
            'actor-42',
            nameRomaji: 'Aoi',
            aliases: ['Aoi A.'],
            profileUrl: 'https://javdb.test/actors/42',
            imageUrl: 'https://images.test/aoi.jpg',
        );
        $request = Request::create('/api/v1/performers/actor-42');

        $summary = (new PerformerSummaryResource($draft))->toArray($request);
        $detail = (new PerformerResource($draft))->toArray($request);

        self::assertNull($summary['uuid']);
        self::assertSame('actor-42', $summary['id']);
        self::assertSame(['Aoi A.'], $summary['aliases']);
        self::assertNull($detail['birth_date']);
        self::assertSame([], $detail['attrs']);
        self::assertSame(['images' => [], 'galleries' => []], $detail['photos']);
    }

    private function performer(array $attributes = []): Performer
    {
        $performer = new Performer();
        $performer->forceFill(array_merge([
            'id' => 42,
            'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'name_romaji' => 'Aoi',
            'name_kanji' => '葵',
            'name_kana' => 'あおい',
            'birth_date' => null,
            'height_cm' => 160,
            'bust' => 86,
            'waist' => 58,
            'hip' => 88,
            'cup' => 'E',
            'blood_type' => 'A',
            'bio_text' => 'Profile text',
            'debut_date' => null,
            'location' => 'Tokyo',
            'profile_url' => 'https://javdb.test/actors/42',
            'image_url' => 'https://images.test/aoi.jpg',
            'attrs' => [],
        ], $attributes));

        return $performer;
    }
}

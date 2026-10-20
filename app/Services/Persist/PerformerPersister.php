<?php

declare(strict_types=1);

namespace App\Services\Persist;

use App\Data\Crawl\PerformerDraft;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerMedia;
use App\Models\PerformerSource;
use App\Services\Archive\MongoSiteArchive;
use App\Services\Crawl\PerformerDraftSink;
use App\Services\Identity\PerformerIdentityService;
use App\Services\Search\ElasticsearchIndexer;
use App\Observability\ObservabilityEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists a rich PerformerDraft from performer-detail crawl (AC-5.2 per-source identity).
 */
final class PerformerPersister implements PerformerDraftSink
{
    public function __construct(
        private readonly MongoSiteArchive $archive,
        private readonly ElasticsearchIndexer $searchIndex,
        private readonly PerformerIdentityService $identity,
    ) {}

    public function accept(PerformerDraft $draft): void
    {
        $this->persistPerformer($draft);
    }

    public function persistPerformer(PerformerDraft $draft, ?array $rawPayload = null): Performer
    {
        $crawledAt = CarbonImmutable::now();
        [$performer, $isNew] = $this->persistLocalPerformer($draft, $crawledAt);
        $mongoId = $this->archivePerformer($draft, $rawPayload);
        $this->linkAndIndex($performer, $draft, $mongoId, $crawledAt);
        $this->emitPersistEvent($draft, $isNew);

        return $performer;
    }

    /** @return array{Performer, bool} */
    private function persistLocalPerformer(PerformerDraft $draft, CarbonImmutable $crawledAt): array
    {
        return DB::transaction(function () use ($draft, $crawledAt): array {
            $existing = Performer::query()
                ->where('source_slug', $draft->sourceSlug)
                ->where('external_id', $draft->externalId)
                ->first();
            $performer = Performer::query()->updateOrCreate(
                ['source_slug' => $draft->sourceSlug, 'external_id' => $draft->externalId],
                $this->performerAttributes($existing, $draft, $crawledAt),
            );

            $this->upsertAliases($performer, $draft);
            $this->upsertProfileImage($performer, $draft, $crawledAt);

            return [$performer->refresh(), $existing === null];
        });
    }

    private function archivePerformer(PerformerDraft $draft, ?array $rawPayload): ?string
    {
        return $this->archive->upsertPerformer(
            $draft->sourceSlug,
            (string) ($draft->profileUrl ?? ''),
            $rawPayload ?? [
                'external_id' => $draft->externalId,
                'name_romaji' => $draft->nameRomaji,
                'name_kanji' => $draft->nameKanji,
                'aliases' => $draft->aliases,
            ],
            $draft->externalId,
        );
    }

    private function emitPersistEvent(PerformerDraft $draft, bool $isNew): void
    {
        app(ObservabilityEmitter::class)->emitPersist('performer', [
            'source_slug' => $draft->sourceSlug,
            'is_new' => $isNew,
        ]);
    }

    /** @return array<string, mixed> */
    private function performerAttributes(?Performer $existing, PerformerDraft $draft, CarbonImmutable $crawledAt): array
    {
        $attributes = [
            'name_romaji' => $this->prefer($existing?->name_romaji, $draft->nameRomaji),
            'name_kanji' => $this->prefer($existing?->name_kanji, $draft->nameKanji),
            'name_kana' => $this->prefer($existing?->name_kana, $draft->nameKana),
            'profile_url' => $this->prefer($existing?->profile_url, $draft->profileUrl),
            'image_url' => $this->prefer($existing?->image_url, $draft->imageUrl),
            'birth_date' => $this->prefer($this->dateString($existing?->getAttribute('birth_date')), $draft->birthDate),
            'height_cm' => $this->preferInt($existing?->height_cm, $draft->heightCm),
            'bust' => $this->preferInt($existing?->bust, $draft->bust),
            'waist' => $this->preferInt($existing?->waist, $draft->waist),
            'hip' => $this->preferInt($existing?->hip, $draft->hip),
            'cup' => $this->prefer($existing?->cup, $draft->cup),
            'blood_type' => $this->prefer($existing?->blood_type, $draft->bloodType),
            'bio_text' => $this->prefer($existing?->bio_text, $draft->bioText),
            'debut_date' => $this->prefer($this->dateString($existing?->getAttribute('debut_date')), $draft->debutDate),
            'location' => $this->prefer($existing?->location, $draft->location),
            'attrs' => $this->mergeAttrs(
                is_array($existing?->getAttribute('attrs')) ? $existing->getAttribute('attrs') : [],
                $draft->attrs,
            ),
            'updated_at' => $crawledAt,
            'crawled_at' => $crawledAt,
        ];

        if ($existing === null) {
            $attributes['source_slug'] = $draft->sourceSlug;
            $attributes['external_id'] = $draft->externalId;
            $attributes['first_seen_at'] = $crawledAt;
            $attributes['needs_review'] = false;
        }

        return $attributes;
    }

    private function linkAndIndex(
        Performer $performer,
        PerformerDraft $draft,
        ?string $mongoId,
        CarbonImmutable $crawledAt,
    ): void {
        $source = PerformerSource::query()->firstOrNew([
            'site_slug' => $draft->sourceSlug,
            'external_id' => $draft->externalId,
        ]);
        $this->identity->preserveRenamedSourceNames(
            $source,
            $performer,
            [$draft->nameRomaji, $draft->nameKanji, $draft->nameKana],
            $draft->sourceSlug,
        );
        $source->fill([
            'performer_id' => $performer->canonical_performer_id ?? $performer->id,
            'source_performer_id' => $performer->id,
            'mongo_id' => $mongoId ?? $source->mongo_id ?? ('local:' . $performer->id),
            'source_url' => $draft->profileUrl ?? $source->source_url,
            'name_romaji' => $draft->nameRomaji ?? $source->name_romaji,
            'name_kanji' => $draft->nameKanji ?? $source->name_kanji,
            'name_kana' => $draft->nameKana ?? $source->name_kana,
            'birth_date' => $draft->birthDate ?? $source->birth_date,
            'cross_link_source' => $draft->crossLinkId === null
                ? $source->cross_link_source
                : ($draft->crossLinkSource ?? $draft->sourceSlug),
            'cross_link_id' => $draft->crossLinkId ?? $source->cross_link_id,
            'removed_at' => null,
            'crawled_at' => $crawledAt,
        ]);
        $source->save();

        $canonicalId = $this->identity->reconcileSource($source);
        $this->identity->syncSourceContributions($source->refresh());
        $canonicalPerformer = Performer::query()->with(['aliases', 'sources'])->find($canonicalId);
        if ($canonicalPerformer instanceof Performer) {
            $this->searchIndex->indexPerformer($canonicalPerformer);
        }
    }

    private function prefer(?string $current, ?string $incoming): ?string
    {
        $incoming = $this->nullableTrim($incoming);
        if ($incoming === null) {
            return $this->nullableTrim($current);
        }

        return $incoming;
    }

    private function preferInt(?int $current, ?int $incoming): ?int
    {
        return $incoming ?? $current;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function upsertProfileImage(
        Performer $performer,
        PerformerDraft $draft,
        CarbonImmutable $crawledAt,
    ): void {
        $imageUrl = $this->nullableTrim($draft->imageUrl);
        if ($imageUrl === null) {
            return;
        }

        PerformerMedia::query()->updateOrCreate(
            [
                'performer_id' => (int) ($performer->canonical_performer_id ?? $performer->id),
                'source_performer_id' => (int) $performer->id,
                'kind' => PerformerMedia::KIND_IMAGE,
                'url' => $imageUrl,
            ],
            [
                'meta' => null,
                'source_slug' => $draft->sourceSlug,
                'crawled_at' => $crawledAt,
            ],
        );
    }

    private function upsertAliases(Performer $performer, PerformerDraft $draft): void
    {
        foreach ($draft->aliases as $alias) {
            $trimmed = trim($alias);
            if ($trimmed === '') {
                continue;
            }

            PerformerAlias::query()->firstOrCreate(
                [
                    'performer_id' => (int) ($performer->canonical_performer_id ?? $performer->id),
                    'alias' => $trimmed,
                    'source_slug' => $draft->sourceSlug,
                    'source_performer_id' => (int) $performer->id,
                ],
                ['kind' => 'source'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeAttrs(array $current, array $incoming): array
    {
        return array_filter(
            [...$current, ...$incoming],
            static fn(mixed $v): bool => $v !== null && $v !== '' && $v !== [],
        );
    }
}

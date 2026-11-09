<?php

declare(strict_types=1);

namespace App\Services\Persist;

use App\Repositories\MovieRepository;
use App\Data\Crawl\PerformerDraft;
use App\Jobs\IndexPerformerJob;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerMedia;
use App\Models\PerformerSource;
use App\Services\Archive\MongoSiteArchive;
use App\Services\Crawl\PerformerDraftSink;
use App\Services\Identity\PerformerIdentityService;
use App\Services\Normalize\PerformerAttributeNormalizer;
use App\Observability\ObservabilityEmitter;
use Carbon\CarbonImmutable;

/**
 * Persists a rich PerformerDraft from performer-detail crawl (AC-5.2 per-source identity).
 */
final class PerformerPersister implements PerformerDraftSink
{
    /** @var list<string> */
    private const RAW_PROFILE_KEYS = [
        'blood_type', 'blood_rh', 'rh', 'cup', 'cup_size', 'bust', 'bust_raw', 'waist', 'waist_raw',
        'hip', 'hips', 'hip_raw', 'height', 'height_cm', 'height_raw', 'size_raw', 'birth_date', 'birth_date_raw',
        'debut_date', 'debut_date_raw', 'birthplace', 'location', 'location_raw', 'raw_profile',
    ];

    private readonly PerformerAttributeNormalizer $attributeNormalizer;

    public function __construct(
        private readonly MongoSiteArchive $archive,
        private readonly PerformerIdentityService $identity,
        ?PerformerAttributeNormalizer $attributeNormalizer = null,
    ) {
        $this->attributeNormalizer = $attributeNormalizer ?? new PerformerAttributeNormalizer();
    }

    public function accept(PerformerDraft $draft): void
    {
        $this->persistPerformer($draft);
    }

    /** Same as persistPerformer(); the name the rest of the application and its tests use. */
    public function persist(PerformerDraft $draft, ?array $rawPayload = null): Performer
    {
        return $this->persistPerformer($draft, $rawPayload);
    }

    public function persistPerformer(PerformerDraft $draft, ?array $rawPayload = null): Performer
    {
        $draft = $this->attributeNormalizer->normalizeDraft($draft);
        $crawledAt = CarbonImmutable::now();
        [$performer, $isNew] = $this->persistLocalPerformer($draft, $crawledAt);
        app(\App\Services\Api\ResponseCacheEpoch::class)->bump();
        $mongoId = $this->archivePerformer($draft, $rawPayload);
        $this->linkAndIndex($performer, $draft, $mongoId, $crawledAt);
        $this->emitPersistEvent($draft, $isNew);

        return $performer;
    }

    /** @return array{Performer, bool} */
    private function persistLocalPerformer(PerformerDraft $draft, CarbonImmutable $crawledAt): array
    {
        return app(MovieRepository::class)->transaction(function () use ($draft, $crawledAt): array {
            $existing = app(MovieRepository::class)->modelQuery(Performer::class)
                ->where('source_slug', $draft->sourceSlug)
                ->where('external_id', $draft->externalId)
                ->first();
            $performer = app(MovieRepository::class)->modelQuery(Performer::class)->updateOrCreate(
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
        $payload = $this->archivePayload($draft, $rawPayload ?? [
            'external_id' => $draft->externalId,
            'name_romaji' => $draft->nameRomaji,
            'name_kanji' => $draft->nameKanji,
            'aliases' => $draft->aliases,
        ]);

        return $this->archive->upsertPerformer(
            $draft->sourceSlug,
            (string) ($draft->profileUrl ?? ''),
            $payload,
            $draft->externalId,
        );
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function archivePayload(PerformerDraft $draft, array $payload): array
    {
        $payload = $this->stripRawProfileFields($payload);

        $payload['normalized_profile'] = [
            'birth_date' => $draft->birthDate,
            'height_cm' => $draft->heightCm,
            'bust' => $draft->bust,
            'waist' => $draft->waist,
            'hip' => $draft->hip,
            'cup' => $draft->cup,
            'blood_type' => $draft->bloodType,
            'blood_rh' => $draft->bloodRh,
            'debut_date' => $draft->debutDate,
            'location' => $draft->location,
        ];

        return $payload;
    }

    /** @param array<string|int, mixed> $payload
     *  @return array<string|int, mixed>
     */
    private function stripRawProfileFields(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key)
                ? strtolower(str_replace([' ', '-'], '_', preg_replace('/(?<!^)[A-Z]/', '_$0', trim($key)) ?? $key))
                : null;
            if ($normalizedKey !== null && in_array($normalizedKey, self::RAW_PROFILE_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->stripRawProfileFields($value) : $value;
        }

        return $clean;
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
        $normalizedExisting = $existing === null ? [] : $this->attributeNormalizer->normalizeStoredAttributes([
            'birth_date' => $existing->getAttribute('birth_date'),
            'height_cm' => $existing->getAttribute('height_cm'),
            'bust' => $existing->getAttribute('bust'),
            'waist' => $existing->getAttribute('waist'),
            'hip' => $existing->getAttribute('hip'),
            'cup' => $existing->getAttribute('cup'),
            'blood_type' => $existing->getAttribute('blood_type'),
            'blood_rh' => $existing->getAttribute('blood_rh'),
            'debut_date' => $existing->getAttribute('debut_date'),
            'location' => $existing->getAttribute('location'),
        ], $draft->sourceSlug, false);
        $attributes = [
            'name_romaji' => $this->prefer($existing?->name_romaji, $draft->nameRomaji),
            'name_kanji' => $this->prefer($existing?->name_kanji, $draft->nameKanji),
            'name_kana' => $this->prefer($existing?->name_kana, $draft->nameKana),
            'profile_url' => $this->prefer($existing?->profile_url, $draft->profileUrl),
            'image_url' => $this->prefer($existing?->image_url, $draft->imageUrl),
            'birth_date' => $this->prefer($normalizedExisting['birth_date'] ?? null, $draft->birthDate),
            'height_cm' => $this->preferInt($normalizedExisting['height_cm'] ?? null, $draft->heightCm),
            'bust' => $this->preferInt($normalizedExisting['bust'] ?? null, $draft->bust),
            'waist' => $this->preferInt($normalizedExisting['waist'] ?? null, $draft->waist),
            'hip' => $this->preferInt($normalizedExisting['hip'] ?? null, $draft->hip),
            'cup' => $this->prefer($normalizedExisting['cup'] ?? null, $draft->cup),
            'blood_type' => $this->prefer($normalizedExisting['blood_type'] ?? null, $draft->bloodType),
            'blood_rh' => $this->prefer($normalizedExisting['blood_rh'] ?? null, $draft->bloodRh),
            'bio_text' => $this->prefer($existing?->bio_text, $draft->bioText),
            'debut_date' => $this->prefer($normalizedExisting['debut_date'] ?? null, $draft->debutDate),
            'location' => $this->prefer($normalizedExisting['location'] ?? null, $draft->location),
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
        $source = app(MovieRepository::class)->modelQuery(PerformerSource::class)->firstOrNew([
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
            'birth_date' => $draft->birthDate ?? $this->attributeNormalizer->date(
                'birth_date',
                $source->getAttribute('birth_date'),
                $draft->sourceSlug,
                false,
            ),
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
        $canonicalPerformer = app(MovieRepository::class)->modelQuery(Performer::class)->with(['aliases', 'sources'])->find($canonicalId);
        if ($canonicalPerformer instanceof Performer && (bool) config('elasticsearch.enabled', true)) {
            IndexPerformerJob::dispatch((int) $canonicalPerformer->getKey())
                ->onQueue((string) config('javmeta_queues.search_queue', 'search'))
                ->afterCommit();
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

    private function upsertProfileImage(
        Performer $performer,
        PerformerDraft $draft,
        CarbonImmutable $crawledAt,
    ): void {
        $imageUrl = $this->nullableTrim($draft->imageUrl);
        if ($imageUrl === null) {
            return;
        }

        app(MovieRepository::class)->modelQuery(PerformerMedia::class)->updateOrCreate(
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

            app(MovieRepository::class)->modelQuery(PerformerAlias::class)->firstOrCreate(
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
        $attributes = array_filter(
            [...$current, ...$incoming],
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );

        $json = json_encode($attributes, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $normalized = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($normalized) ? $normalized : [];
    }
}

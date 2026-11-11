# Genre normalization (JOOXCR-407, Part 2: report and design only)

**Status: proposal. Nothing in this document has been applied.** No migration file exists, no production data was
written, and no behavior of the running application changes. Production changes need explicit owner approval (see
[Approval gate](#approval-gate)).

Companion file: [`genre-normalization-top200.csv`](genre-normalization-top200.csv), the proposed canonical mapping.

## Problem statement

The `genres` table stores one row per distinct source label (`label_normalized` is unique, `label_raw` keeps the first
spelling seen). `MoviePersister::upsertGenres` normalizes only by lower-casing and collapsing whitespace, then links the
movie with a `movie_genres` row per `(movie_id, genre_id, source_slug)`. Every source spells the same concept differently,
so one concept is fragmented over many rows, scripts and languages:

| Concept | Labels in the top 500 | Largest single label | Sum of all labels |
|---|---|---|---|
| Creampie | `Creampie` 104,514; `中出し` 27,472; `Internal Cum` 3,000 | 104,514 | 134,986 |
| Big breasts | `Big Tits` 76,550; `巨乳` 19,681; `Big Boobs` 2,989; `Big Breasts` 2,409 | 76,550 | 101,629 |
| Solo title | `Solowork` 99,830; `Solo Title` 1,791; `単体作品` 478 | 99,830 | 102,099 |
| Amateur | `Amateur` 60,013; `素人` 7,700; `素人動画` 12,156; `Ordinary Person` 1,146 | 60,013 | 81,015 |
| Mature woman | `Mature Woman` 38,490; `熟女` 8,445; `Mature` 2,057; `Milf` 3,528 | 38,490 | 52,520 |
| Beautiful girl | `Beautiful Girl` 28,872; `美少女` 7,837; `Pretty Girl` 1,713 | 28,872 | 38,422 |

Consequences today:

- The genre catalog (`MovieRepository::movieCatalog('genres')`) and the movie facets list groups by label, so the same
  concept appears several times with split counts.
- Genre filtering (`MovieRepository` genre filter) matches `label_normalized` / `label_raw` with `LIKE '%term%'`. A search
  for `Creampie` does not return movies tagged only `中出し`, and the reverse.
- `/meta/genres` returns the union of labels, with no canonical name or Vietnamese display name.
- Non-genre labels (resolution, mosaic type, studio, product-code prefix, performer name) are mixed in with real genres.

Measured facts: the data below comes from the top 500 production genres by movie link count, read on 2026-11-07 with
read-only `psql` against `192.168.10.12` / `xcrawler` and saved as `/home/joos/Workspaces/.tmp/codex/jooxcr-407-top500-genres.tsv`
(`name<TAB>count`). The sum of all 500 counts is 2,007,016 links.

Limits of the measurement (unknowns): the total number of distinct genres in production beyond the top 500 was not
measured; the counts are taken as `movie_genres` link counts, and since the primary key includes `source_slug` the same
movie can be linked to one genre by two sources, so a count may exceed the number of distinct movies. Exact query text and
whether `DISTINCT movie_id` was used were not re-verified for this report.

## Analysis of the top 500

### Script distribution (verified from the TSV)

Classified by Unicode range of the label (kana present, Han only, Latin letters, other):

| Script | Labels | Links | Share of links |
|---|---|---|---|
| Latin (English, romaji, codes) | 350 | 1,667,053 | 83.1% |
| Japanese with kana (may include kanji) | 92 | 189,707 | 9.5% |
| Han characters only (kanji or hanzi) | 56 | 143,553 | 7.2% |
| Other (digits or symbols, `69`, `18+`) | 2 | 6,703 | 0.3% |

Observations:

- English dominates by volume because the largest sources already publish English tags. Japanese labels are 29.6% of the
  top-500 labels but only 16.6% of links; they are the long tail that is not folded into the English top labels.
- Inference: labels that are Han-only, such as `巨乳`, `熟女`, `人妻`, `素人`, `痴女`, are shared by Japanese and Chinese. No
  label in the top 500 can be shown to be Chinese-only (for example Simplified-only characters), so ZH synonym coverage in
  this report is limited to such shared forms. Simplified/Traditional variants, if present, are in the tail below rank 500
  and must be re-measured before the alias table is finalized.
- Spelling variants inside one language also fragment: `Hi Def` / `Hi-Def` / `Hd`, `3P・4P` / `3P、4P` / `3p, 4p`,
  `寝取り・寝取られ` / `寝取り、寝取られ` / `寝取り・寝取られ・ＮＴＲ` (full-width letters), `淫乱・ハード系` /
  `淫乱、ハード系`, and typos such as `Masterbation`.
- The current normalizer does not apply Unicode compatibility normalization, so full-width `ＮＴＲ` and half-width `NTR` are
  different keys. The alias lookup key proposed below applies NFKC and case folding.

### Synonym clusters found (verified mapping, human judgment)

The mapping CSV groups the 500 labels into **256 canonical rows**; every one of the 500 labels is assigned to exactly one
row, except four compound labels (`3P・4P`, `3P、4P`, `3p, 4p`, `Threesome / Foursome`) that intentionally map to both
`threesome` and `foursome`.

| Result | Count |
|---|---|
| Labels analysed | 500 |
| Canonical rows | 256 (−48.8% vs. labels) |
| Clusters with 2 or more aliases | 128, covering 376 labels |
| Of those, genre-kind clusters | 116 |
| Clusters that join an English and a Japanese/Chinese label | 112 (104 genre-kind) |
| Single-label rows | 128 |

Canonical rows by `kind` (recorded in the CSV `notes` column): `genre` 212, `studio_or_source` 16, `format` 13, `series_code`
14, `performer` 1.

The target of about 200 rows is exceeded (256) because the unmatched labels are kept as single-label rows with a Vietnamese
display name so that every top-500 label is covered; the first 212 `genre` rows are the real genre list, and the other
44 rows are not genres and should leave the genre facet.

Strongest clusters (full list in the CSV; `sample_count` is the sum of per-label link counts and double counts movies
linked through two aliases of the same cluster):

| canonical_key | display_vi | Aliases | sample_count |
|---|---|---|---|
| `creampie` | Bắn trong | Creampie; 中出し; Internal Cum | 134,986 |
| `solowork` | Phim một diễn viên | Solowork; Solo Title; 単体作品 | 102,099 |
| `big_tits` | Ngực lớn | Big Tits; 巨乳; Big Boobs; Big Breasts | 101,629 |
| `amateur` | Nghiệp dư | Amateur; 素人; 素人動画; Ordinary Person | 81,015 |
| `oral` | Oral (khẩu giao) | Oral; Blow; Blowjob; フェラ; Fellatio; Oral Sex | 72,720 |
| `married_woman` | Phụ nữ đã kết hôn | Married Woman; 人妻; Wife | 64,233 |
| `mature_woman` | Phụ nữ trung niên | Mature Woman; 熟女; Mature; Milf | 52,520 |
| `beautiful_girl` | Cô gái xinh đẹp | Beautiful Girl; 美少女; Pretty Girl | 38,422 |
| `slut` | Dâm đãng (nữ chủ động) | Slut; 痴女; Slutty Women | 35,491 |
| `squirting` | Phun nước | Squirting; Squirt; 潮吹き | 25,739 |
| `slender` | Mảnh mai | Slender; スレンダー; Slim | 27,537 |
| `shaved` | Cạo lông | Shaved; Shaved Pussy; パイパン; Paipan; Glabrousness; Hairless Pussy | 24,262 |
| `cuckold_ntr` | Cắm sừng / NTR | Cuckold; 寝取り・寝取られ; 寝取り、寝取られ; 寝取り・寝取られ・ＮＴＲ; NTR | 22,436 |
| `paizuri` | Kẹp ngực | Titty Fuck; Tit Fuck; パイズリ | 21,240 |
| `cowgirl` | Tư thế cưỡi | Cowgirl; 騎乗位; Riding; Ride | 21,092 |
| `masturbation` | Thủ dâm | Masturbation; オナニー; Masterbation | 24,442 |
| `facial` | Bắn lên mặt | Facials; Facial; 顔射; Facial Cumshot; Cum-on-Face; 顔に精液 | 16,999 |
| `school_girl` | Nữ sinh | School Girls; 女子校生; Schoolgirl | 17,945 |
| `handjob` | Thủ dâm bằng tay | Handjob; 手コキ | 16,335 |
| `pov` | Góc nhìn thứ nhất | POV; 主観; Subjectivity | 17,919 |
| `drama` | Phim truyện | Drama; ドラマ | 16,393 |
| `cunnilingus` | Liếm âm hộ | Cunnilingus; クンニ | 13,723 |
| `anal` | Hậu môn | Anal; アナル; アナルセックス | 13,311 |
| `bukkake` | Bukkake | Bukkake; ぶっかけ | 11,799 |

After mapping, several top concepts gain 29% to 36% over their largest single label (`creampie` +29%, `big_tits` +33%,
`amateur` +35%, `mature_woman` +36%).

### Judgment calls and open questions (need owner review)

Each CSV row has a `confidence` value in `notes` (`H` high, `M` medium, `L` low). Inferences, not verified facts:

- **Compound labels** `3P・4P` and similar map to both `threesome` and `foursome`; this requires a many-to-many alias
  model (see [Design](#genre_aliases-and-canonical_genres-design)).
- **Kept separate on purpose** (not merged by similarity): `Breasts`/`Tits` (generic) vs `big_tits`; `Girl` vs
  `beautiful_girl`; `Featured Actress` vs `solowork`; `Orgy`/`乱交` vs `Gang Bang`; `Confinement` vs `Restraint`;
  `Hardcore` is merged into `nasty_hardcore` only because JAV sources use `淫乱・ハード系` / `Nasty, Hardcore` as one tag.
- **Medium-confidence merges** worth a second look: `Wife` into `married_woman`, `Ordinary Person` into `amateur`, `Milf`
  into `mature_woman`, `Relatives` into `incest`, `Back` into `doggy_style`, `Mini` into `petite`.
- **Data-quality flags**: two pairs of unrelated concepts have identical counts: `Hardcore` and `Incest` (both 11,867) and
  `Hd` and `Deep Throating` (both 5,955). This is suspicious (possible source/crawler error), but only the count is known.
  Re-check those rows in production before approving the corresponding aliases.
- **Not genres**: resolution/format (`ハイビジョン`, `4K`, `Blu-ray`, `VR`, mosaic types, `4HR+`), platforms and makers
  (`FC2 PPV`, `Caribbeancom`/`カリビアンコム`, `1Pondo`/`一本道`, `10musume`/`天然むすめ`, `HEYZO`, `Moodyz`, `Prestige`),
  product-code prefixes (`JUQ`, `SSIS`, `MIDV` ...) and a performer name (`波多野結衣`). They are cross-script duplicates too
  (`Caribbeancom` / `カリビアンコム`), so they are aliased like the others and marked with `kind`, so the owner can decide
  whether to hide them from the genre facet or move them to maker/label/series fields.

## genre_aliases and canonical_genres design

Principle: the source labels in `genres` and the links in `movie_genres` are provenance and stay untouched. Canonical
genres and aliases are an additive overlay that read paths can use or ignore.

### `canonical_genres`

| Column | Type | Notes |
|---|---|---|
| `id` | `increments` (unsigned int) | Same key style as `genres.id`. |
| `canonical_key` | `text`, not null | Stable ASCII snake_case identifier such as `creampie`; unique. Never reused. |
| `display_vi` | `text`, not null | Vietnamese display name. |
| `display_en` | `text`, not null | English display name. |
| `kind` | `text`, not null, default `genre` | `genre`, `format`, `studio_or_source`, `series_code`, `performer`. Controls which facets show it. |
| `is_visible` | `boolean`, not null, default `true` | Hide a row from facets without deleting. |
| `created_at`, `updated_at` | `timestampTz` | |

Indexes and constraints: primary key `id`; `unique(canonical_key)`; check constraint on `kind` values.

### `genre_aliases`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigIncrements` | |
| `canonical_genre_id` | unsigned int, FK `canonical_genres.id` | `restrictOnDelete`: deleting a canonical genre with aliases is refused. |
| `alias_key` | `text`, not null | NFKC + case-folded + whitespace-collapsed label, the lookup key. Computed by one shared function used by seeder, persister and search. |
| `alias_raw` | `text`, not null | Label as written by the source (display and audit). |
| `script` | `text`, not null | `latin`, `kana`, `han`, `other`; informational, supports JP/ZH/EN reporting. |
| `confidence` | `char(1)`, not null, default `M` | `H`, `M`, `L` from the review; `L` rows are not applied automatically. |
| `seed_batch` | `text`, not null | Identifier of the seed or backfill run that created the row (rollback handle). |
| `created_at` | `timestampTz` | |

### `genre_alias_genres` (the source labels behind an alias)

Several source labels can share one alias key (`NTR` and `ＮＴＲ` fold to the same key), so an alias row cannot hold a single
`genre_id`. Each alias links to every source label row it covers through an association table:

| Column | Type | Notes |
|---|---|---|
| `alias_id` | unsigned bigint, FK `genre_aliases.id`, `cascadeOnDelete` | |
| `genre_id` | unsigned int, FK `genres.id`, `cascadeOnDelete` | Every `genres` row whose folded label equals the alias key. |

`primary key(alias_id, genre_id)` and `index(genre_id)` (serves the join `movie_genres.genre_id -> genre_alias_genres ->
genre_aliases -> canonical_genres`). A canonical genre therefore counts the movies of all of its source labels, and no label's
movies are left out when two labels share a key.

Uniqueness and indexes:

- `unique(alias_key, canonical_genre_id)`. One alias may map to several canonical genres (compound labels) but never twice
  to the same one.
- `index(alias_key)` serves search and persister lookups (exact match).
- `index(canonical_genre_id)` serves "all aliases of a canonical genre" and the admin view.
- `index(seed_batch)` serves rollback.

Growth: aliases are bounded by the number of distinct source labels (hundreds to low tens of thousands, genre-label growth
only when a new source or label appears); not a high-volume table. `movie_genres` is the large table (the top 500 alone have
2,007,016 links) and is not modified.

## Migration sketch (illustrative only, not a migration file)

Do not add this file to `database/migrations/` as part of JOOXCR-407 Part 2. It shows the shape to be reviewed.

```php
// ILLUSTRATIVE ONLY, do not commit as a migration without owner approval.
//
// Design note
// Purpose:        map many source labels (JP/ZH/EN) to one canonical genre.
// Access:         alias_key exact lookup (persister, search input); genre_alias_genres join (counts, facets);
//                 canonical_genre_id for admin; seed_batch for rollback.
// Growth:         bounded by distinct labels (not per-movie); movie_genres untouched.
// Compatibility:  additive and forward-only; old code ignores the new tables.
// Rollback:       down() drops the three new tables; no existing table is altered.
// Privileges:     ordinary application role; no superuser needed.
Schema::create('canonical_genres', function (Blueprint $table): void {
    $table->increments('id');
    $table->text('canonical_key')->unique();
    $table->text('display_vi');
    $table->text('display_en');
    $table->text('kind')->default('genre');
    $table->boolean('is_visible')->default(true);
    $table->timestampsTz();
});

Schema::create('genre_aliases', function (Blueprint $table): void {
    $table->id();
    $table->unsignedInteger('canonical_genre_id');
    $table->text('alias_key');
    $table->text('alias_raw');
    $table->text('script');
    $table->char('confidence', 1)->default('M');
    $table->text('seed_batch');
    $table->timestampTz('created_at')->useCurrent();

    $table->foreign('canonical_genre_id')->references('id')->on('canonical_genres')->restrictOnDelete();
    $table->unique(['alias_key', 'canonical_genre_id']);
    $table->index('alias_key');
    $table->index('canonical_genre_id');
    $table->index('seed_batch');
});

// One alias key can cover several source labels (NTR, ＮＴＲ): every label keeps its own link.
Schema::create('genre_alias_genres', function (Blueprint $table): void {
    $table->unsignedBigInteger('alias_id');
    $table->unsignedInteger('genre_id');

    $table->primary(['alias_id', 'genre_id']);
    $table->foreign('alias_id')->references('id')->on('genre_aliases')->cascadeOnDelete();
    $table->foreign('genre_id')->references('id')->on('genres')->cascadeOnDelete();
    $table->index('genre_id');
});
```

Optional later step, only if measurement shows the join is too slow for facet counts: a denormalized
`movie_canonical_genres(movie_id, canonical_genre_id)` table with a composite primary key and an index on
`canonical_genre_id`, maintained by the persister and rebuilt by the backfill command. It is not part of this proposal's
first stage.

## Repository, service and backfill design

Names follow the existing layout (`app/Repositories`, `app/Services`, `app/Console/Commands`) and are proposals.

- **Models**: `CanonicalGenre`, `GenreAlias` (plain Eloquent, no timestamps on `GenreAlias` beyond `created_at`).
- **`GenreAliasKey` (or equivalent static helper)**: single normalization function, NFKC, case fold, trim, collapse
  whitespace. Existing `genres.label_normalized` is left as is; the new key is only stored on aliases and used for lookup.
- **`GenreAliasRepository`**: `resolveCanonicalIds(string $label): list<int>`, `canonicalIdsForGenreIds(array)`,
  `aliasesOf(int $canonicalId)`, `upsertSeedRows(array $rows, string $batch)`, `deleteBatch(string $batch)`,
  `unmappedGenres(int $minLinks)`. Read methods are cached per request; writes only go through the seed command.
- **`GenreCanonicalizer` service**: reads the CSV (or a DB-backed list), validates it (every alias unique per canonical,
  keys match `^[a-z0-9_]+$`, `kind` allowed, `confidence` allowed, compound aliases listed on every target row), and applies
  it through the repository. It never edits `genres` or `movie_genres`.
- **`MoviePersister::upsertGenres`** keeps storing the raw label and link as today. In **stage 1** (before canonical reads
  are switched on) it also links a newly created `genres` row to the aliases whose key equals its folded label
  (`GenreAliasRepository::linkGenre`, one insert into `genre_alias_genres`, in the same transaction), so a label created
  after the backfill is never left outside the canonical count. A label with no alias is not an error: it falls back to
  itself in every read path. As a safety net `genres:aliases-report` lists linked-looking labels that have no link, and
  `genres.use_canonical` is not enabled while that list is not empty.
- **Commands** (modelled on `performers:backfill-normalization`: dry-run by default, `--apply`, chunked):
  - `genres:aliases-seed {csv} [--apply] [--batch=<id>] [--min-confidence=M]`: validate and print a plan (rows to add, rows
    already present, alias keys that match no `genres` row, conflicts). `--apply` writes inside a transaction and tags rows
    with `seed_batch`. Safe to re-run (upsert on `(alias_key, canonical_genre_id)`).
  - `genres:aliases-link [--apply] [--chunk=N] [--after=ID]`: fill `genre_alias_genres` for existing source labels in ID
    chunks, printing the last ID for resume.
  - `genres:aliases-report [--min-links=N]`: top labels without alias, alias rows without a matching label, equal-count
    anomalies, and canonical counts (DISTINCT movies) versus raw label counts. Read-only.
  - `genres:aliases-rollback --batch=<id> [--apply]`: delete the aliases created by one batch (dry-run by default).

The backfill never rewrites `movie_genres`, so it is cheap (alias rows, not movie rows) and can run on the shared database
without long locks.

## How counts, search and facets would use aliases

All paths use a feature switch (proposed setting `genres.use_canonical`, default `false`) and fall back to current behavior
when it is off or when a label has no alias.

- **Counts** (`movieCatalog('genres')`, movie facets `genres`): join
  `movie_genres -> genre_alias_genres -> genre_aliases -> canonical_genres`, group by `canonical_key`, count `COUNT(DISTINCT
  movie_id)` so a movie tagged with both `Creampie` and `中出し` counts once. Unmapped genres are grouped by their own
  label as today. The row shows `display_vi` or `display_en` by UI language; `is_visible = false` and `kind <> 'genre'`
  rows are excluded from the genre facet.
- **Search / filter** (`genre` and `genre_all` parameters): resolve the input with the alias key **and** with the canonical
  key (`canonical_genres.canonical_key`, because a canonical key such as `school_uniform` is not necessarily listed as an
  alias). A query `中出し`, `Creampie` or the canonical key `creampie` yields the canonical id(s); the movie filter becomes
  `EXISTS (movie_genres JOIN genre_alias_genres JOIN genre_aliases ... WHERE canonical_genre_id IN (...))`, **combined with
  the existing `LIKE` on the label columns for each term** (`canonical match OR substring match`). The substring match is
  kept even when the term resolves to an alias, so a filter `Anal` still finds a movie tagged only `Anal Toy`, as it does
  today. For `genre_all=1` there is one such condition per term. A quoted term (an exact facet tick) uses the canonical
  match when the value resolves to a canonical genre; when it resolves to none (an unmapped genre) it uses exact label
  equality, as today, so a tick always returns the movies the facet counted.
- **Suggest and autocomplete**: return canonical rows and show their aliases, so typing `中出` suggests `creampie`.
- **Elasticsearch/embeddings**: the search document would need canonical keys in addition to raw labels, followed by a
  reindex. This is not verified against the current mapping; it requires its own change and is out of scope here.
- **API/MCP/UI**: current responses remain valid. Canonical keys would be added as additive fields; display strings follow
  the bilingual catalogs from JOOXCR-410.

## Reversibility and rollback

1. Source data is never changed. `genres`, `movie_genres` and `label_raw` stay exactly as crawled, so there is nothing to
   restore.
2. Turn off `genres.use_canonical` and every read path returns to current behavior immediately; no deploy is needed.
3. Remove one bad seed: `genres:aliases-rollback --batch=<id>` deletes only that batch's alias rows. A wrong merge is
   corrected by deleting or editing one alias row, not by splitting movie data.
4. Full removal: first deploy code without the stage-1 writer (`MoviePersister::upsertGenres` linking) and drain every old
   instance, because `genres.use_canonical` only controls reads and a running writer would fail and roll back a movie write
   once the tables are gone; only then run the migration `down()`, which drops `genre_alias_genres`, `genre_aliases` and
   `canonical_genres` (no other table depends on them).
5. Before any production run: dry-run output saved, a database backup point confirmed by the owner, the run first applied on
   the staging copy, and counts compared (`genres:aliases-report`) before and after.
6. Canonical keys are never renamed or reused after they appear in an API response; to retire one, set `is_visible = false`.

## Approval gate

**No production change happens until the owner approves.** Specifically, without explicit owner approval:

- no migration is created, merged or run on production;
- no seed or backfill command is run with `--apply` against production;
- no data in `genres`, `movie_genres`, Elasticsearch or caches is changed;
- the CSV mapping stays a proposal; the owner confirms the low- and medium-confidence rows, the `kind` handling (hide or
  move non-genre labels) and the Vietnamese display names before it is seeded.

Suggested next steps after approval: add the migration and models in a worktree with its own database (`<slug>_wt<id>`), cover
the key function and resolver with tests, run the seed on staging, compare counts, then schedule the production run.

## JOOXCR-342 quality-framework inventory

This inventory describes checked-in code at baseline commit `71be206` (`origin/develop`). It is based on repository
inspection only; no runtime database or live data was accessed. The historical top-500 report and JOOXCR-407 proposal
above are unchanged. “Current” below means implemented in that code snapshot.

### Existing behavior by entity and surface

| Entity or surface | Existing behavior and entry points | Status against the requested framework |
|---|---|---|
| Movies | `MoviePersister` calculates completeness from a fixed list of 11 fields and sets `needs_review` on a sharp field drop. `ReportService::quality()` reports field coverage, the 8-of-11 count, tiers, conflicts, and the movie review count. | Movie quality reporting and review flags exist. The inspected rules are code-defined; no shared configurable movie-rule catalog or per-rule evaluation report is present. `app/Services/Persist/MoviePersister.php:130-142,596-618`; `app/Services/Reports/ReportService.php:13-70` |
| Performer profiles and identity | `javmeta_normalization` configures performer allow-lists, junk tokens, field ranges, and location aliases. Settings metadata and `PerformerNormalizationSettingsValidator` expose and validate those shapes; `PerformerAttributeNormalizer` applies them and counts rejections by entity, field, and source. Separately, `PerformerIdentityService` records open candidate comparisons and sets `needs_review`. | Configurable profile rules and identity review are separate, performer-specific mechanisms. Neither establishes a reusable policy contract across entities; the identity candidate records are not connected to the proposed taxonomy kind. `config/javmeta_normalization.php:7-31`; `config/javmeta_settings.php:312-317`; `app/Services/Settings/PerformerNormalizationSettingsValidator.php:9-56`; `app/Services/Normalize/PerformerAttributeNormalizer.php:11-26,380-405`; `app/Services/Identity/PerformerIdentityService.php:477-495` |
| Genre labels | `MoviePersister::upsertGenres()` trims, collapses whitespace, lowercases, and stores the source label. `MetaGenreController` returns distinct raw-or-normalized labels; catalog, facet, and filter queries group or match those labels. | Raw-label normalization and browsing are implemented. Canonical aliases, NFKC/case-fold lookup, confidence application, and canonical filtering remain proposals in JOOXCR-407. `app/Services/Persist/MoviePersister.php:379-400`; `app/Http/Controllers/MetaGenreController.php:13-31`; `app/Repositories/MovieRepository.php:103-129,323-352,545-649` |
| `format`, `studio_or_source`, `series_code`, and taxonomy `performer` | These are the other four of five proposed `kind` values, alongside `genre`. The proposal lists candidate labels, while the application represents movie maker, label, series, codes, and performer identity in separate fields and services. | The proposal does not connect these kinds to runtime canonical records or rule evaluation. Their ownership and mapping semantics are unverified; `studio_or_source` must not be assumed to mean the same thing as maker, label, or crawler source. The taxonomy `performer` kind is not shown to be wired to the separate performer identity service. `docs/03-operations/genre-normalization.md:145-197,262-289`; `app/Models/Movie.php:29-33`; `app/Models/MovieCode.php:17-27`; `app/Services/Identity/PerformerIdentityService.php:245-260` |
| Reports and metrics | The admin quality report covers movie aggregates. The `performers:normalization-report` command and `NormalizationRejectionMetrics` expose performer rejection totals by field and source. Published quality metrics include movie `needs_review`, open `ReviewFlag` records, and completeness tiers. | These are separate, existing operational views; they do not report framework-wide rule outcomes or taxonomy coverage, unmapped aliases, or confidence distributions. `genres:aliases-report` is only a proposed command. `app/Http/Controllers/Admin/ReportController.php:12-28`; `app/Console/Commands/NormalizationReportCommand.php:11-42`; `app/Observability/NormalizationRejectionMetrics.php:9-26`; `app/Console/Commands/Observability/PublishMetricsCommand.php:607-629` |
| Approval UI | The admin report view displays aggregate quality values. Movie `ReviewFlag` rows record sharp field drops or consecutive crawler failures; the recovery path resolves consecutive-failure rows. Performer identity candidates are recorded separately in `performer_identity_reviews`. | The checked-in admin API routes do not expose taxonomy or performer-identity candidate approval actions. These records and the aggregate movie report do not provide human approval decisions for normalization or taxonomy. `routes/api.php:40-47`; `public/ui/js/views/admin.js:10-23`; `app/Models/ReviewFlag.php:9-20`; `app/Services/Persist/MoviePersister.php:655-667`; `app/Repositories/CrawlTitleFailureServiceRepository.php:87-99`; `app/Services/Identity/PerformerIdentityService.php:477-495` |

### Rule status

- **Genre key folding:** current code lowercases and collapses whitespace. NFKC and Unicode case folding are proposed for alias lookup, not applied to persisted labels.
- **Canonical taxonomy and visibility:** canonical keys, display names, `kind`, visibility, alias links, and compound mappings are design proposals. The baseline contains no runtime canonical-genre or alias model, migration, or resolver.
- **Confidence and human review:** `H`/`M`/`L` confidence values and the rule that low-confidence rows are not automatically applied exist in the proposal and CSV notes. No checked-in evaluator consumes those values.
- **Configuration:** performer rules are configurable and shape-validated. A cross-entity rule registry, shared precedence/conflict behavior, and rule-version semantics are not present in the inspected code.
- **Failure and observability:** performer normalization can reject a field while keeping crawling available if its rejection counter cannot be written. Movie quality and open `ReviewFlag` records have aggregate metrics. A common per-rule result model spanning movies, performers, and taxonomy is missing.

### Independently scoped follow-up work

1. Define the cross-entity rule contract and its configuration lifecycle, including which entities and fields each rule may affect.
2. Add separately reviewable rule adapters and acceptance coverage for movies, performer profiles, genre aliases, and any approved non-genre taxonomy kinds. Resolve whether maker, label, crawler source, series, and product-code prefixes are distinct targets before mapping them.
3. Extend quality reports and bounded metrics to show rule outcomes and taxonomy coverage without conflating existing movie aggregates, performer rejections, and crawl failures.
4. Design and implement a permission-checked human review workflow for proposed mappings, with explicit decisions and an audit trail; aggregate quality display alone is not approval.
5. Treat applying the JOOXCR-407 genre mapping as its own implementation slice after the owner-reviewed mapping, taxonomy kinds, and compatibility behavior are settled.

This is a gap inventory, not a complete rule-framework or taxonomy design. The entity semantics and approval policy above remain unverified until agreed in their follow-up work.

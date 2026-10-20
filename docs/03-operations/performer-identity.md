# Canonical performer identity

Each source performer profile remains stored as its own performer row. The
`performer_sources` table maps those source records to a canonical performer.
An identity match is automatic only when normalized names match exactly and
both records have the same birth date, or when they share the same namespaced
cross-link ID. Name similarity alone never merges records. Uncertain candidates
remain separate and are listed by the identity review report.

Names are normalized with Unicode compatibility normalization, case folding,
punctuation removal, and token sorting. This accepts family/given name order
changes. A cross-script match requires a shared name variant and the same birth
date, unless a strong cross-link establishes identity. Source names, aliases,
source URLs, and active/removal state remain available on canonical API, MCP,
and search records.

The `performer_identity.merge_name_similarity` threshold remains `1.0` so names
must match exactly after normalization. `performer_identity.review_name_similarity`
controls which weaker name matches appear for review; it never permits a merge.
The backfill narrows candidates with a trigram block, which is only guaranteed to
cover every reviewable pair at `0.82` or higher. Below `0.82` the backfill compares
against every canonical performer so no configured review is omitted; this is
slower on large catalogs.

## Matching during crawls

Movie, gallery and performer-detail ingestion reconcile every source record
they save, so strong matches merge and ambiguous exact-name matches enter the
review report without waiting for the backfill. To keep the crawl path cheap,
candidates are selected with indexed lookups only: canonical performers that
share the same namespaced cross-link, or the same normalized name stored in
`performer_name_keys`. Fuzzy `weak_name_match` candidates are found only by
`performers:backfill-identities`, which also fills `performer_name_keys` for
existing records. Run the backfill once after deploying so existing performers
can be matched by the crawl path.

The backfill streams source records in id order and looks up each one's
candidates through `performer_name_keys` and `performer_name_grams` (name
trigrams). Candidate ids are read in keyset pages of 200 and the merge and
review report is printed row by row instead of collected. The matcher keeps at
most `performer_identity.max_candidates_per_source` (default 50) review
candidates per source record, the most similar ones, and only counts strong
matches beyond that, so the per-source state is bounded by that setting rather
than by the catalog or a common trigram. A source with more review candidates
than the limit reports only the best ones. Tests feed the matcher a hundred
thousand candidates and assert the result stays within the limit and the peak
memory growth stays small.
The dry run executes the same pass inside a transaction that is rolled back, so
it lists exactly the merges and reviews a real run would produce.

## Deployment

After deploying the code, apply the forward migration, inspect the planned
merges, and run the backfill:

```bash
php artisan migrate --force
php artisan performers:backfill-identities --dry-run
php artisan performers:backfill-identities
php artisan performers:identity-report
```

Reindex search documents after the backfill so existing canonical performer
documents include all source records:

```bash
php artisan es:setup --reindex
```

## Backfill and review

Run a dry run to list planned merges and ambiguous candidates:

```bash
php artisan performers:backfill-identities --dry-run
php artisan performers:identity-report
```

Apply the idempotent backfill after reviewing the plan:

```bash
php artisan performers:backfill-identities
```

The command records each merge and leaves ambiguous candidates separate. A
source removal keeps its mapping and the canonical performer. Mark the source
profile inactive with:

```bash
php artisan performers:source-removed <source_slug> <external_id>
```

When a source record returns, its next crawl marks it active again.

## Split and undo

Every merge creates an event with the moved source links and performer
associations. Use the merge event ID to split the identities, and the resulting
split event ID to undo that split:

```bash
php artisan performers:split <merge_event_id>
php artisan performers:undo-split <split_event_id>
```

Rows added after the merge (aliases, media, movie links) are handed to the
split-off performer. A canonical movie link copied at merge time is removed on
split only while it is untouched; if a later crawl re-made that link on the
canonical record (its source or crawl time changed), the canonical record keeps
it. The split-off performer regains its own link either way.

Both operations are recorded and update the canonical search documents.
They verify that the recorded performer group and source links still match the
event before changing them. Resolve later identity changes first if a split or
undo is rejected.

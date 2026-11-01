# Canonical performer identity

Each source performer profile remains stored as its own performer row. The
`performer_sources` table maps those source records to a canonical performer.
An identity match is automatic only when normalized names match exactly and
both records have the same birth date, or when they share the same namespaced
cross-link ID, or when the safe [co-credit rule](#co-credit-identity) applies.
Name similarity alone never merges records. Uncertain candidates
remain separate and are listed by the identity review report.

Names are normalized with Unicode compatibility normalization, case folding,
punctuation removal, and token sorting. This accepts family/given name order
changes. A cross-script match requires a shared name variant and the same birth
date, unless a strong cross-link or unambiguous co-credit establishes identity. Source names, aliases,
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
review report is printed row by row instead of collected. The matcher streams
the candidates of one source record: one pass counts strong and reviewable
matches, and the review list is a lazy generator that re-reads the candidates,
so matcher state is constant however many candidates a source has. Every
ambiguous candidate is reported, strong matches first and then weaker reviews,
each in candidate order; a single strong match merges and still records the
other ambiguous candidates for review. The price is up to three passes over the
candidate pages of a source record. `performers:identity-report` pages the
open reviews by id and prints one line per review instead of loading them all.
A unit test asserts that ten times the candidates need no more memory.
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

The movie links a merge copies are recorded per merge event in
`performer_identity_event_movie_links` (not in the event JSON). Merge, split and
undo read and write them in pages of 500 with batched statements, so their cost
does not grow per movie. Merge events created before this table existed keep the
inline `created_movie_links` list and are still read.

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

## Co-credit identity

A third automatic signal, `co_credit`, requires the same full name after the
existing normalization and token sorting, with at least two tokens. Single-token
names (including Aika, Ami and Arisa) never qualify, regardless of length.
Both original source records must be credited on the same movie row. For that
normalized name the movie must contain exactly one source record from each of
the two sites. Two same-named cast members on either side are ambiguous.
Candidates use `performer_name_keys` and indexed movie credits; there is no fuzzy
lookup. Movie ingestion saves the complete cast before reconciling identities.

When both groups have conflicting nonempty birth dates, blood types or heights,
the co-credit rule refuses the merge. The review stays open with a
`co_credit_conflicting_*` reason. Existing name-plus-birth-date and namespaced
cross-link rules retain their precedence and behavior. Name similarity alone
still never merges, and `performer_identity.merge_name_similarity` stays `1.0`.
Multiple eligible canonical candidates remain separate for review.

A co-credit merge closes only open reviews between the two merging groups
(including reviews pointing at their previous canonical IDs), with status `resolved_co_credit`, reason `co_credit` and `resolved_at`.
Unrelated reviews remain open. Merge details include the rule, both evidence
source record IDs, the previous group mapping, relation owners. Closed review snapshots are paged into
`performer_identity_event_reviews` so a large group does not grow the event JSON. Shared movie evidence is streamed to
`performer_identity_event_movie_links`, with `evidence_source_performer_id` and
`evidence_candidate_performer_id`. Historical and newly copied canonical movie
links have null evidence IDs. Split/undo must only remove/restore copied links,
never the original shared evidence credits.

`performers:identity-unmerge <event>` reuses the guarded split implementation
and restores the previous canonical mapping and reviews. It records a split
event and refreshes search. Resolve subsequent identity changes first if the
command refuses an older event. The existing `performers:split` and
`performers:undo-split` commands remain available.

### Operator sequence

1. Apply the additive migration (two nullable columns on the event movie-link
   table and a new empty review-snapshot table; no update or rewrite of the
   performer table).
2. Run `php artisan performers:backfill-identities --dry-run --rule=co_credit`.
   The entire pass runs in a rolled-back transaction, including review changes
   and audit records. Search writes are suppressed for dry runs.
3. Review the sample of up to 20 planned co-credit merges: both names, source
   sites and shared movie display code. Check the per-rule counters
   `name_and_birth_date`, `cross_link`, `co_credit`, `reviews_closed` and
   `conflicts_kept`. The last counter counts unordered conflicting source pairs.
4. Take and verify the normal database backup before changing mappings.
5. Apply slices with
   `php artisan performers:backfill-identities --rule=co_credit --limit=1000`.
   The limit caps successful merges, so repeating the command progresses past
   unresolved records. Re-run until `co_credit: 0`. Omit `--rule` to include the
   existing rules and weak-name reviews. Candidate/source pages and the sample
   are bounded in memory; only the legacy convenience `backfill()` API collects
   its report, while the command uses the streaming API.
6. Run `php artisan performers:identity-report`, then
   `php artisan es:setup --reindex`. Canonical search documents include every
   source record after merge; source records are never deleted.

A dry run holds a transaction for its entire pass; use `--limit` for bounded
merge slices and allow enough database transaction time. Do not run identity
backfills against production before the sample has been reviewed and the
backup is verified.

# Enabled sources that do no work

An enabled source that has no due work costs a slot and can hide a defect. Each finding below says which of the three possible
causes applies, with the numbers it rests on and the date it was measured. The numbers are historical evidence of that day: queue
rows, circuit state and gaps change, so check the current state of the source before diagnosing a new idle period.

The three causes: **discovery is broken** (the listing pages are not found or not crawled), **the site is exhausted** (everything it
offers is already crawled), or **the schedule or the gap is wrong** (work exists but is not due or is paced too slowly).

## eporner (JOOXCR-326), measured 2026-11-28

**Cause: none of the three. The source is a one-URL gallery sample by design and has nothing to discover.**

| Measure | Value | Where |
|---|---|---|
| Enabled | yes | `sources.enabled` |
| Last success | 2026-10-23 22:09:45 UTC, 36 days before the measurement | `sources.last_success_at` |
| Queue rows ever created | 1 (kind `gallery`, status `done`, due 2026-10-23 22:08:09 UTC) | `crawl_queue` |
| Listing rows (pages discovered and crawled) | 0 | `crawl_queue` |
| Rows pending or due | 0 | `crawl_queue` |
| Fetch attempts recorded | 0 | `crawl_fetch_attempts` |
| Circuit | closed, 0 consecutive failures | `sources` |
| Gap | 7.5 s current, 8 s default, 4 to 60 s allowed | `sources` |

What the code says:

- `config/javmeta_sources.php` gives eporner one `gallery_urls` entry and no listing URL. `crawler:seed` queues that one gallery
  (`SeedCommandTest::test_seed_enqueues_gallery_for_eporner`).
- The CrawlerX eporner adapter declares only the `gallery` capability, and its one default target is a paused `gallery_sample`
  that is not paginated. There is no listing type that could find more galleries.

Why each cause is ruled out:

- *Discovery broken:* there is no discovery to break; the adapter has no listing capability and javmeta has no listing URL for it.
- *Site exhausted:* the site was never crawled beyond one sample gallery, so nothing says it is exhausted.
- *Schedule or gap wrong:* the gap is the normal 8 s default and the circuit is closed; the one row that exists was served on time.

What it would take to make the source produce work: a listing (and detail) capability in the CrawlerX eporner adapter and a
listing URL here, which is a CrawlerX change with its own ticket. Until then the source can stay enabled without effect or be
switched off in Cài đặt; that choice belongs to the owner. The staleness watchdog and the staging readiness check already name
it as a source that is not due under its cycle.

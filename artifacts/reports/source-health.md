# Source health

Generated: 2026-10-24T13:38:03+00:00

| Source | Page | Verdict | Error | Reason |
|---|---|---|---|---|
| demo | listing | BLOCKED | blocked | Blocked by the site |

## Repository discovery inventory — J345a

**Inspected:** 2026-11-11. Source claims below come from tracked code and
configuration, not a live crawl, external-site request, or persistent database
query. Test verification uses saved fixtures and in-memory SQLite. The dated
table above is a previous source-health result, not evidence of current listing
coverage.

### Configured listing entry points

| Source | Configured listing seeds | JavMeta listing kinds |
|---|---|---|
| JAVDatabase | Performer: `https://www.javdatabase.com/idols/` (`config/javmeta_sources.php:79-87`) | Performer listing only |
| JAVLibrary | Movie: `https://www.javlibrary.com/en/vl_newrelease.php`; performer: `https://www.javlibrary.com/en/star_list.php` (`config/javmeta_sources.php:88-97`) | Movie and performer listings |

`crawler:seed` runs every minute and visits enabled sources in configured
priority order. It enqueues their configured listing URLs (default limit 50
per source), and rechecks page-one seeds while a listing chain is still
running. Finished chains restart from page one after the configured pause
(`routes/console.php:62-65`; `app/Console/Commands/Crawler/SeedCommand.php:20-40,65-90`;
`config/javmeta_sources.php:30-35`).

For movie listings, `FetchListingJob` queues parsed item URLs and the returned
`pagination.nextUrl` as another movie listing. `FetchPerformerListingJob` does
the same for performer listings (`app/Jobs/FetchListingJob.php:65-89`;
`app/Jobs/FetchPerformerListingJob.php:65-89`). JavMeta's `CrawlerxClient` is
a thin adapter over the `jooservices/crawlerx` package; the source-specific
fetch and parsing implementation is not in this repository
(`app/Services/Crawler/CrawlerxClient.php:18-22,59-72`; `composer.json:17`).
Saved JAVDatabase and JAVLibrary listing fixtures exist, and the contract
tests send fixtures through the matching JavMeta jobs; those fixtures prove
only the recorded examples, not current site behavior
(`tests/Fixtures/crawlerx/javdatabase/listing_page1.html`;
`tests/Fixtures/crawlerx/javlibrary/listing-live.html`;
`tests/Fixtures/crawlerx/javlibrary/performer-listing-live.html`;
`tests/Feature/Contract/CrawlerxFixtureContractTest.php:16-48`).

### Scheduling and request pacing

The scheduler invokes `crawler:feed-pool --scheduled` every five seconds, but
the command applies the configured ten-second feed interval. The feed pool
scales its 50-per-minute dispatch limit across that interval, uses a rolling
60-second pacing window, and limits unfinished claims to four per source and
kind (`routes/console.php:64-65`; `config/javmeta_queues.php:76-93`;
`app/Console/Commands/Crawler/FeedPoolCommand.php:40-49,78-99,424-458`).
Per-source waiting work and recent request starts count toward the rolling
budget; the feed pool delays later work using the source's effective gap
(`app/Console/Commands/Crawler/FeedPoolCommand.php:300-339`).

Both sources configure a ten-second default gap, with five- to 45-second
minimum/maximum bounds. The throttle raises the gap on rate limits, blocks,
challenges, timeouts, and network failures, and reduces it after success
(`config/javmeta_sources.php:79-96`;
`app/Services/Crawl/SourceThrottle.php:34-54,57-94`). These are configured
bounds and scheduling rules, not measured throughput. Current database
overrides or gap values and observed request rates were not inspected.

### Coverage evidence and unknowns

The repository publishes `crawl_runs` activity counters by source for the
previous hour, including `pages_fetched` (`app/Console/Commands/Observability/PublishMetricsCommand.php:652-672`;
`docs/03-operations/telemetry.md:57-60`). In the inspected discovery path,
`crawler:seed` creates and completes a run with page and movie counters set to
zero, while listing jobs record parsed item/enqueue counts in crawl events
(`app/Console/Commands/Crawler/SeedCommand.php:52-63,92-102`;
`app/Jobs/FetchListingJob.php:86-89`;
`app/Jobs/FetchPerformerListingJob.php:86-89`). These activity counters do
not provide an expected catalog total, a source-completeness ratio, or proof
that an external catalog total is reachable.

No sitemap discovery configuration or implementation was found under
`app/`, `config/`, `routes/`, or `database/` (`git grep -i sitemap -- app
config routes database` returned no matches). Because source-specific parsing
is delegated to CrawlerX and no live inspection was in scope, sitemap support
for either source and the current pagination/terminal behavior of either site
remain unverified. The scheduled `crawler:source-health` command is a separate
live diagnostic and runs daily at 04:00; it was not run for this inventory
(`routes/console.php:77`; `app/Services/Health/SourceHealthChecker.php:11-18`).

### Follow-on slices

1. Define the required entity scope and measurable coverage denominator for
   each source; JAVDatabase currently has only a performer seed in JavMeta
   configuration, so movie coverage must not be assumed.
2. Implement bounded JAVDatabase performer-list traversal, including explicit
   page termination, deduplication, source pacing, and fixture-backed tests.
3. Implement bounded JAVLibrary movie-list traversal with its own progress and
   stopping criteria.
4. Implement bounded JAVLibrary performer-list traversal as a separate slice
   with its own progress and stopping criteria.
5. Add sitemap discovery only after source-specific evidence establishes a
   supported sitemap format; test that path independently from listing pages.
6. Add per-source discovery progress and throughput reporting against the
   agreed denominator; do not label activity counters as catalog completeness.

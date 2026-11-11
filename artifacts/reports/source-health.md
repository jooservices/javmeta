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

The scheduler invokes `crawler:feed-pool --scheduled` every five seconds, while
the command gates work on the configured feed interval (default ten seconds).
The default 50-per-minute global dispatch ceiling is scaled across that
interval and applied separately to the `direct` and `browser` lanes; `retry`
has a separate default ceiling of five per minute. These are lane ceilings, not
measured or guaranteed rates (`routes/console.php:64-65`;
`config/javmeta_queues.php:35-38,84-101`;
`app/Console/Commands/Crawler/FeedPoolCommand.php:78-84,160-179,195-217,424-458`).

Direct and browser work share source-level pacing: queued work and recent
starts count against a rolling 60-second per-source budget. Non-retry claims
are limited to four unfinished jobs per source; browser work also has an
eight-claim in-flight cap. Retry has a separate in-flight cap of one job per
source, a rolling 60-second budget, and twice the source's effective gap.
Later work is delayed using its lane's effective gap, so retry's effective
dispatch can be lower still (`config/javmeta_queues.php:33-39,79-93`;
`app/Console/Commands/Crawler/FeedPoolCommand.php:89-109,195-217,300-339,411-420`;
`app/Repositories/CrawlQueueRepository.php:213-249,526-554`).

Both configured source definitions have a ten-second default gap and five- to
45-second minimum/maximum bounds. These are not the effective hard bounds for
all failures: without a source-gap override, rate limits, blocks, and
challenges double the current gap up to the greater of the source maximum and
the configured blocked ceiling (600 seconds by default); timeouts and network
failures increase it by 50% up to the source maximum. With an override, its
min/default/max replace the source definition and its maximum caps both blocked
and other gap increases, so the default blocked ceiling does not apply
(`config/javmeta_sources.php:79-96`;
`config/javmeta_settings.php:146`;
`config/javmeta_queues.php:100-101`;
`app/Services/Crawl/SourceGapPolicy.php:35-68,77-100`;
`app/Services/Crawl/SourceThrottle.php:34-54,61-89`). Successful requests
reduce the gap toward the applicable minimum. Current runtime settings,
database overrides, and stored gap values were not inspected, and no request
rates or throughput were measured; configured defaults and bounds do not
establish effective runtime limits or achievable throughput.

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

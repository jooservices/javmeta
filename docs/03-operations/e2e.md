# End-to-end journeys

The E2E suite exercises JAVmeta through its real HTTP kernel and shared test
services. CrawlerX uses the checked-in HTML fixtures, so the test run makes no
requests to public sites. Run the suite with `composer test:e2e` inside the
shared PHP runtime; `docker/ci/e2e-shared` (or `JAVMETA_MODE=wt:<id> docker/ci/e2e-shared`) runs it on
`javmeta_test` / the worktree database through `docker/dev/shared-run`.

## Journey steps

| Step | Purpose |
| --- | --- |
| `ResetTestState` | Truncate only the configured JAVmeta test PostgreSQL database, clear Mongo collections individually, and recreate only the named E2E Elasticsearch indexes. |
| `StartStack` | Check the public health response, worker heartbeat, and source crawl timestamp without exposing catalog data. |
| `CreateApiKey`, `ListApiKeys`, `Authenticate`, `RevokeApiKey` | Exercise owner creation/list/revocation and immediate consumer authentication over HTTP. |
| `LookupMovie`, `BulkLookupMovies`, `WaitForMovie` | Check normalized lookup, missing records, bulk limits/statuses, and visibility after fixture crawling. |
| `SearchMovies` | Check performer names and aliases, combined filters, validation, deterministic cursor pages, and an empty page beyond the result set. |
| `GetPerformer` | Check performer names, aliases, linked titles, and URL-only photo metadata. |
| `CallMcp` | Send JSON-RPC tools over `/api/v1/mcp` and validate the decoded tool result. |
| `SeedCatalog`, `SeedFixtureSource`, `RunCrawlOnce` | Create deterministic API data and run the real crawler/persistence pipeline against a checked-in fixture. |
| `FindCrawledMovie` | Find the fixture-crawled movie over REST by code lookup, by the `code` filter or by text search, or expect a rejected request (401) without catalog data. |
| `McpFindCrawledMovie` | Find the fixture-crawled movie through the MCP tools `get_movie`, `lookup_movies` and `search`, or expect a rejected call (401) without a tool result. |
| `RunCrawlOutage`, `CheckSourceHealth` | Crawl while the source answers 503 (the crawl fails, nothing is persisted) and check how `/api/health` reports the source: failing, then recovered. |

## Acceptance coverage

| Business rule | E2E assertion | Existing focused suite |
| --- | --- | --- |
| AC-3: normalized movie-code lookup and misses | J3 | `MovieLookupControllerTest`, `NormalizedCodeTest` |
| AC-4: search filters, aliases, validation, stable pagination | J4 | `MovieSearchControllerTest` |
| AC-6: bulk results, missing ratio, duplicate normalization, hard limit | J3 | `MovieBulkControllerTest` |
| AC-7: key creation, one-time plaintext, listing, revoke, rate limiting | J2 | `ApiKeyControllerTest`, `RateLimitTest` |
| AC-9: health, source success and public response boundary | J1 | `HealthControllerTest`, `RuntimeDependencyHealthTest` |
| MCP HTTP lookup/search/count/status | J6 | `McpToolServiceTest`, MCP controller tests |
| Performer detail and photo references | J5 | performer controller/resource tests |
| A crawled movie is found by code and text search | J7 | `MoviePipelineTest`, `MovieSearchControllerTest` |
| The same crawled movie is found through MCP | J8 | `McpToolServiceTest`, MCP controller tests |
| Revoked consumer and MCP keys lose access immediately | J9 | `ApiKeyControllerTest` |
| A source goes down (crawl fails, health reports it) and comes back (recrawl succeeds) | J10 | `CrawlerxFailureContractTest`, `HealthControllerTest` |

J7 to J10 follow one movie that the real pipeline crawled from the onejav fixture. Semantic search is proven by JAVM-93, not here.

Each reusable step has a unit test with Laravel's fake HTTP transport. The
journey suite itself talks to the started application over HTTP. Failures name
the scenario and step and pass the exchange through `Redactor`; API key and
owner-token values never appear in assertion output.

## Namespace and coverage policy

The runner accepts only the project test database, Mongo database, search
indexes, cache prefix, Redis prefix, and OpenObserve stream supplied through
`shared-run` (`javmeta_test`, `javmeta_wt<id>` or the CI-only `javmeta_it`). It uses Laravel's database cache and synchronous queue
so the journeys do not write Redis or RabbitMQ state. A per-run OpenObserve
stream is deleted during runner cleanup. PostgreSQL cleanup truncates tables in
that test database while preserving migrations. Mongo collections are dropped
one by one; the database is never dropped. Elasticsearch cleanup touches only
the configured movie and performer test indexes.

`tests/E2E` is test infrastructure rather than application production code, so
it is not added to the application's default coverage denominator. The E2E
configuration includes the reusable journey library as its own coverage source
and `composer test:e2e` enforces at least 90% line coverage for it. The process
bootstrap and fixture HTTP server are excluded from that library denominator.

To add a journey, compose typed `Step` objects in a named `Scenario`, share
state only through `Context`, add a focused fake-HTTP unit test for every new
step, and update the acceptance mapping above.

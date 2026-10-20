# javmeta

[![CI](https://github.com/jooservices/javmeta/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/jooservices/javmeta/actions/workflows/ci.yml)

JavMeta crawls Japanese video metadata with [CrawlerX](https://github.com/jooservices/crawlerx), normalises it into one record per
movie and per performer, stores it, makes it searchable (by text and by meaning) and serves it through a REST API and MCP.

```
sources (24 on) -> crawl_queue (buffer) -> Redis queues + Horizon -> CrawlerX -> normalise -> Postgres (system of record)
                                                                                      |-> MongoDB (raw archive)
                                                                                      '-> Elasticsearch (text + vector search)
                                                         REST API and MCP read Postgres and Elasticsearch
```

## Site capability matrix

25 sources are configured in `config/javmeta_sources.php`; 24 are crawled. Switch a source off with `JAVMETA_DISABLED_SOURCES`
(comma separated slugs, default `avfan`) and keep `CRAWLERX_DISABLED_SITES` the same. A switched-off source keeps its queued rows
and resumes when it is switched on again. Four sources need the browser services (Jable, MissAV, JavLibrary, JavBus).

| Source | Name | What is crawled |
|---|---|---|
| `141jav` | 141Jav | movies |
| `aisex` | Aisex | performers |
| `avfan` | Avfan | movies (**switched off by default**) |
| `avfan_profiles` | Avfan Profiles | performers |
| `avjoho` | Avjoho | performers |
| `caribbeancom` | Caribbeancom | movies |
| `duga` | DUGA | movies |
| `eporner` | EPORNER | galleries |
| `fc2` | FC2 Content Market | movies |
| `ffjav` | FFJav | movies |
| `heyzo` | HEYZO | movies |
| `jable` | Jable | movies, performers |
| `javbtc` | JavBTC | movies |
| `javbus` | JavBus | movies, performers |
| `javdatabase` | JAV Database | performers |
| `javdb` | JavDB | movies |
| `javlibrary` | JAVLibrary | movies, performers |
| `javphotos` | JavPhotos | galleries |
| `minnanoav` | Minnano AV | performers |
| `missav` | MissAV | movies |
| `onejav` | OneJav | movies |
| `onepondo` | 1Pondo | movies |
| `tokyohot` | Tokyo-Hot | movies |
| `warashi` | Warashi | performers, galleries |
| `xcity` | XCITY | movies, performers |

Check which sources work right now: `php artisan crawler:source-health [--source=slug] [--json]` fetches one public page per kind
of page and reports `WORKING`, `DEGRADED` or `BLOCKED` with the reason (also written to `artifacts/reports/source-health.md`).

## How a crawl works

1. `crawler:seed` puts the start pages of every enabled source into **`crawl_queue`**, the buffer and source of truth (one row per
   source and URL, so nothing is queued twice).
2. `crawler:feed-pool` (every minute) claims rows and pushes them to the Redis queues `listing`, `detail`, `performer_listing`,
   `performer_detail` and `gallery`; each queue holds at most `JAVMETA_QUEUE_DEPTH_LIMIT` (100) jobs, so a large backlog stays in the
   database. **Horizon** supervises the workers (plus a `search` queue for indexing).
3. A job calls CrawlerX, normalises the answer, saves it to Postgres, archives the raw data in MongoDB and queues the search indexing.
4. Listings follow their next pages; when the last page is reached the listing starts again after `JAVMETA_LOOP_PAUSE_SECONDS`
   (300), and the first page is read again every `JAVMETA_FRESH_INTERVAL_MINUTES` (60) so new releases appear quickly.
5. A **blocked** site waits and retries (`JAVMETA_BLOCK_DELAYS`, default 60,120,180 seconds; while it waits the whole site pauses),
   then the row fails. Other failures retry up to 3 times (`max_attempts`) with growing delays; a claim that sits longer than
   900 seconds is returned to the queue without using an attempt if the job never started.
6. A movie with missing data is crawled again after `JAVMETA_RECRAWL_DAYS` (7), at most `JAVMETA_RECRAWL_MAX` (3) rounds
   without new data (`crawler:recrawl-incomplete`, `crawler:recrawl-stopped`).

Useful commands: `crawler:run-source`, `crawler:failures --since=24h`, `crawler:reclaim`, `crawler:watchdog`, `crawler:sync-sources`.
Details: [docs/03-operations/queue.md](docs/03-operations/queue.md).

## Where the data lives

| Store | Role | Configuration |
|---|---|---|
| PostgreSQL | System of record: movies, performers, observations per source, identities, API keys | `DB_*` |
| MongoDB | Raw archive per source (`movie_sources` links to it) | `MONGO_URI`, `MONGO_DATABASE` (`MONGODB_URI` and `MONGODB_DATABASE` are accepted too) |
| Elasticsearch 9 | Text and vector search; documents carry the `uuid` of the Postgres row | `ELASTICSEARCH_HOST`, `ELASTICSEARCH_USERNAME` + `ELASTICSEARCH_PASSWORD` or `ELASTICSEARCH_API_KEY`, `ELASTICSEARCH_MOVIES_INDEX`, `ELASTICSEARCH_PERFORMERS_INDEX` |
| Redis | Queues (Horizon) and caches | `REDIS_*`, `QUEUE_CONNECTION=redis` |

**One record per thing.** A movie is one record whatever the number of sources (matched on the normalised code); each source's values
are kept as observations and the best value wins by source priority. A performer is one record per real person: sources merge only on
strong signals (same normalised name and birth date, or the same cross-link id), never on a name alone; merges can be split and undone.

**Search.** Create the indexes once with `php artisan es:setup` (`--reindex` re-indexes everything). Movies and performers are also
embedded (multilingual E5, 384 dimensions) by the embedder service (`EMBEDDER_URL`) and found by meaning with kNN search. Missing vectors
are filled by `php artisan search:backfill-embeddings`. `JAVMETA_SEARCH_ENABLED=false` skips indexing and `JAVMETA_EMBEDDER_ENABLED=false`
skips the embedder; crawling and the API keep working.

## API and MCP

REST API (key in the `X-Api-Key` header; create keys with the owner token):

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/movies/{code}` | One movie by code (`ssis001`, `SSIS-1`, ` ssis-001 ` all resolve) |
| `GET /api/v1/movies?q=...` | Search and filters, cursor pagination |
| `POST /api/v1/movies:bulk` | Many codes in one call |
| `GET /api/v1/performers`, `GET /api/v1/performers/{id}` | Performers |
| `GET /api/v1/meta/genres` | Genre list |
| `GET /api/v1/auth/verify` | Key check |
| `GET /api/health`, `GET /api/health/live` | Health (no key) |
| `GET/POST/DELETE /api/v1/admin/keys` | Key management (owner token, `X-Owner-Token`) |
| `GET /api/v1/admin/report/{quality,cost,sources}` | Reports (owner token) |

Contract and error codes: [docs/03-operations/api-contract.md](docs/03-operations/api-contract.md).

Movie and performer detail responses (`get_movie`, `get_performer`, and the
corresponding detail API endpoints) expose the same photo contract:

```json
{
  "photos": {
    "images": [
      {
        "url": "https://cdn.example/image.jpg",
        "thumbnail_url": "https://cdn.example/thumb.jpg",
        "crawled_at": "2026-10-01T00:00:00+00:00",
        "source": "javphotos"
      }
    ],
    "galleries": [
      {
        "id": "gallery-123",
        "title": "Example gallery",
        "url": "https://example/gallery-123",
        "source": "javphotos",
        "crawled_at": "2026-10-01T00:00:00+00:00",
        "images": [
          {
            "url": "https://cdn.example/image.jpg",
            "thumbnail_url": "https://cdn.example/thumb.jpg",
            "crawled_at": "2026-10-01T00:00:00+00:00",
            "source": "javphotos"
          }
        ]
      }
    ]
  }
}
```

`source` is the stable source slug. Only URL references are stored; image
binary content is not downloaded into JAVMeta.

**MCP** (JSON-RPC 2.0) is served at `POST /api/v1/mcp` with any active API key, and over stdio for local clients. Tools:
`lookup_movies`, `get_movie`, `search`, `lookup_performers`, `get_performer`, `get_counts`, `crawl_status`, `system_status`.
Every tool input and an example call are in [docs/03-operations/mcp.md](docs/03-operations/mcp.md).

## Reports and checks

- `php artisan javmeta:report quality|cost|sources` (and the three admin endpoints): fill rate of the 11 core fields, completeness,
  conflicts between sources, crawl cost with a projection to 100k and 400k titles, and source freshness.
- `php artisan javmeta:verify-sample <file>`: compare a list of codes with the stored data.
- `php artisan javmeta:smoke`: checks the database, Redis, MongoDB, Elasticsearch, the embedder, Horizon, the scheduler, the queue depth,
  an API lookup and MCP in one run (exit code 0 or 1, `--json`).

## Observability

- **Sentry**: errors of the API, workers, scheduler and MCP, with tags (role, node, source, crawl kind, error code); release is the
  git short SHA baked into the image. Set `JAVMETA_SENTRY_DSN`; empty means off. Test: `php artisan javmeta:sentry-test`.
- **OpenObserve**: logs and metrics with per-source dashboards and alert rules (`observability:import`); `OPENOBSERVE_*` settings.
- **Telegram** alerts for dead sources, a stalled crawl and an old backup (`NOTIFICATION_TELEGRAM_*`).
- **Health**: `GET /api/health` shows dependencies, queue counts and each source's last success.
See [docs/03-operations/monitoring.md](docs/03-operations/monitoring.md) and [telemetry.md](docs/03-operations/telemetry.md).

## Backups

Encrypted Postgres, MongoDB and Elasticsearch backups with retention, a restore and a rehearsal live in `deploy/backup`; see
[docs/03-operations/backup.md](docs/03-operations/backup.md).

## Environments

- **Workspaces shared stack (development, AI Flow):** services come from the shared Docker (Postgres, MongoDB, Redis, Elasticsearch,
  OpenObserve, Sentry, the embedder) and the shared PHP 8.5 runtime; the browser services (FlareSolverr, Playwright) run on the Dev VM
  (`FLARESOLVERR_URL`, `PLAYWRIGHT_URL`; the old `CRAWLERX_FLARESOLVERR_URL` is deprecated). Every ticket gets its own databases
  (`javmeta_wt<id>`).
- **Staging (WSL):** the main clone on `develop`, served at `http://javmeta.localhost:8085`, kept running by the systemd units in
  `deploy/systemd` and updated by `deploy/staging.sh` (see [deployment.md](docs/03-operations/deployment.md)).
- **Production:** image-based runbook in [docs/03-operations/deployment.md](docs/03-operations/deployment.md).
- **Own Docker (for outside developers):** `cp -n .env.example .env`, set `APP_KEY`, then `make build install up migrate`; passwords in
  `.env` must be set (no sample passwords are accepted in staging or production).

## Fetch and Cloudflare bypass

Workers run CrawlerX, which tries plain HTTP, curl impersonation, then a browser (Playwright) and FlareSolverr.

| Piece | Where |
|---|---|
| FlareSolverr | `FLARESOLVERR_URL` (`/v1`) |
| Browser fetch service | `PLAYWRIGHT_URL` |
| Playwright and stealth scripts | `CRAWLERX_PLAYWRIGHT_SCRIPT`, `CRAWLERX_PUPPETEER_SCRIPT` |

## Make targets

| Target | Meaning |
|---|---|
| `make up` / `down` | Start/stop full local stack (keeps `./data`) |
| `make up MODE=crawler` | Production crawler instance: `worker,flaresolverr` (data/obs external) |
| `make up MODE=control` | Production control instance (one only): `api,scheduler,mcp,embedder` (crawl/data dependencies external) |
| `make up MODE=node` | Production single node: control + crawler (data/obs external) |
| `make migrate` | Run migrations |
| `make migrate MODE=fresh` | Reset and recreate the database schema (local only) |
| `make setup SERVICE=elasticsearch` | Create/update Elasticsearch mappings |
| `make setup SERVICE=elasticsearch REINDEX=1` | Create/update mappings and re-index all data |
| `make crawl ACTION=tick` | Enqueue listings for all enabled sites |
| `make crawl ACTION=dispatch` | Buffer → Laravel jobs on named queues |
| `make crawl ACTION=source SITE=onejav` | Tick and dispatch one site |
| `make scheduler` | One-off scheduler |
| `make test` | PHPUnit tests |
| `make test-integration` | Integration suite against real Postgres, Mongo, Elasticsearch and OpenObserve (Docker) |
| `make lint` | Full Composer quality gate |

## CI

GitHub Actions on every pull request to `develop`: lint and tests (coverage at least 90 percent, three-run stability check),
integration (Postgres, MongoDB, Elasticsearch, OpenObserve), end-to-end journeys, secrets scan, workflow audit, commit and PR title
checks. Details: [docs/03-operations/ci.md](docs/03-operations/ci.md).

## Project docs

- `BUSINESS.md` / `ARCHITECTURE.md` / `IMPLEMENTATION.md` / `DESIGN-DISCUSSION.md`
- `docs/03-operations/`: api-contract, mcp, queue, backup, monitoring, telemetry, deployment, e2e, ci, runtime-dependencies

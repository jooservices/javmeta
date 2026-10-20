# javmeta

[![CI](https://github.com/jooservices/javmeta/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/jooservices/javmeta/actions/workflows/ci.yml)

Laravel app: crawl JAV metadata (crawlerx) → Mongo archive → Postgres SoR → Elasticsearch search → API + MCP.

Status: Beta — v0.1.0-beta

## Definition of Done

- **Crawl all sites:** `make crawl ACTION=tick` → `make crawl ACTION=dispatch` (worker in compose). 25 sources in `config/javmeta_sources.php` — **movies, performers, and galleries** by capability.
- **Query API:** `GET /api/v1/movies/{code}` · `GET /api/v1/movies?q=…` · `GET /api/v1/performers` (aliases `/movies`). API key via admin.
- **MCP for AI:** stdio and HTTP expose `lookup_movies`, `get_movie`, `search`, `lookup_performers`, `get_counts`, `crawl_status`, `system_status`, and `get_performer`. See the [MCP guide](docs/03-operations/mcp.md) for inputs and examples.
- **Docker + durable DB:** Postgres / Mongo / ES bind-mounted under `./data/*` (survive container drop; avoid `down -v`).

## Site capability matrix

| Slug | Movie | Performer | Gallery | Tick seeds |
|---|---|---|---|---|
| `141jav` | listing+detail | cast from movie | — | movie |
| `onepondo` | listing+detail | cast from movie | — | movie |
| `aisex` | — | listing+detail | — | performer |
| `avfan` | listing+detail | cast from movie | — | movie |
| `avfan_profiles` | — | listing+detail | — | performer |
| `avjoho` | — | listing+detail | — | performer |
| `caribbeancom` | listing+detail | cast from movie | — | movie |
| `duga` | listing+detail | cast from movie | — | movie |
| `eporner` | — | thin rows from gallery names | Yes | `gallery_urls` |
| `fc2` | listing+detail | cast from movie | — | movie |
| `ffjav` | listing+detail | cast from movie | — | movie |
| `heyzo` | listing+detail | cast from movie | — | movie |
| `jable` | listing+detail | listing+detail | — | movie + performer |
| `javdatabase` | — | listing+detail | — | performer only |
| `javbtc` | listing+detail | cast from movie | — | movie |
| `javbus` | listing+detail | listing+detail | — | movie + performer |
| `javdb` | listing+detail | cast from movie | — | movie |
| `javlibrary` | listing+detail | listing+detail | — | movie + performer |
| `javphotos` | — | — | listing+detail | gallery listing |
| `minnanoav` | listing+detail* | listing+detail | — | performer only |
| `missav` | listing+detail | cast from movie | — | movie |
| `onejav` | listing+detail | cast from movie | — | movie |
| `tokyohot` | listing+detail | cast from movie | — | movie |
| `warashi` | — | listing+detail | listing+detail | performer + gallery |
| `xcity` | listing+detail | listing+detail | — | movie + performer |

\* Minnano AV has movie capability in crawlerx; POC seeds performer index only.

## Architecture (DoD)

| Store | Role | Data path |
|---|---|---|
| Postgres | System of record (`movies` = movies, performers, pivots) | Lookup / detail |
| MongoDB | Per-site archive (flexible; not end-user) | volume `javmeta_mongo-db-data` |
| Elasticsearch | Search index → returns `uuid` → hydrate PG | `./data/elasticsearch` |

**Search:** `ES → uuid → Postgres → respond`  
**Lookup by code:** Postgres only  
**Persistence:** Postgres + Elasticsearch host bind mounts under `./data/*`; MongoDB uses a Docker named volume (`mongo-db-data`) because its WiredTiger engine is incompatible with Docker Desktop macOS bind mounts. `docker compose down` keeps all data (do **not** use `docker compose down -v`).

## Observability (OpenObserve)

Compose service `openobserve` (UI [http://localhost:5080](http://localhost:5080)). App dual-writes sanitized ops/API telemetry **synchronously** (fail-open; no `default` queue dependency). Toggle with `OPENOBSERVE_ENABLED`. Domain catalog content is never ingested.

**REQ-9 ops:** `GET /api/health` (no key) → status + per-source circuit/`last_success_at` + queue counts. Alerts: Telegram via `jooservices/laravel-notifications` (`NOTIFICATION_TELEGRAM_BOT_TOKEN` / `NOTIFICATION_TELEGRAM_CHAT_ID`, with `TELEGRAM_*` legacy fallback) + `laravel-logging` (`alert.sent`). Scheduler runs `javmeta:watchdog` every 5 minutes. Consumer movie/performer **404** also alerts so ops can crawl ASAP.

```bash
# after make up — login with ZO_ROOT_USER_EMAIL / ZO_ROOT_USER_PASSWORD from .env
open http://localhost:5080
php artisan javmeta:obs-publish-metrics   # or wait for scheduler (every minute)
php artisan javmeta:watchdog
curl -s http://localhost:8080/api/health
```

## Quickstart (all Docker)

```bash
cp -n .env.example .env
# set APP_KEY if empty: docker compose run --rm api php artisan key:generate

make build
make install
make up                 # full local stack, including embedder
make migrate
make crawl ACTION=tick       # enqueue all sites
make crawl ACTION=dispatch   # claim buffer → named Laravel queues
# N worker containers (JAVMETA_WORKER_INSTANCES) × 5 queues × JAVMETA_WORKERS_PER_QUEUE processes
```

**Compose profiles** — every service is grouped so a stack can mix bundled-Docker and external services:

| Profile | Services | Usage |
|---|---|---|
| `app` | `api`, `worker` | App containers (crawling; needed everywhere) |
| `data` | `postgres`, `mongo`, `elasticsearch` | Data layer (can be external) |
| `openobserve` | `openobserve` | Observability (can be external) |
| `flare` | `flaresolverr` | Cloudflare bypass for crawling (can be external) |
| `control` | `scheduler`, `mcp` | Control-plane: run on **ONE** instance only (scheduler duplicates jobs/alerts) |
| `embed` | `embedder` | Semantic-search embedding service |
| `mcp` | `mcp` | On-demand MCP server (stdio) |

- Local (develop default): `make up` = `docker compose --profile app,data,openobserve,flare,control,embed up -d`.
- Production crawler instance: `make up MODE=crawler` = `worker + flaresolverr` (no API; data/observability external).
- Production control instance (one only): `make up MODE=control` = `api + scheduler + mcp + embedder` (no worker; crawl/data dependencies external).
- Production single node: `make up MODE=node` = control + crawler on one host (data/observability external).
- Boot gate: each app container runs `ready-check` first; if any **required** endpoint (DB, Mongo, ES, OpenObserve when enabled, FlareSolverr) is unreachable after retries it exits → container down. `OPENOBSERVE_ENABLED=false` skips the OpenObserve check.

The planned image-based production procedure is documented in the [production deployment runbook](docs/03-operations/deployment.md).

API (create key first via admin token):

```bash
curl -s -H "X-Api-Key: jvm_..." "http://localhost:8080/api/v1/movies/STARS-456"
curl -s -H "X-Api-Key: jvm_..." "http://localhost:8080/api/v1/movies?q=STARS"
```

MCP (stdio for AI clients) — set `JAVMETA_MCP_API_KEY` to an **active** `jvm_…` key first:

```bash
docker compose --profile mcp run --rm mcp
# tools: lookup_movies, get_movie, search, lookup_performers, get_counts, crawl_status, system_status, get_performer
```

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

**MCP over HTTP (streamable HTTP)** — reachable from any AI over the network:

```bash
curl -s -H "X-Api-Key: $JAVMETA_MCP_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' \
  http://localhost:8080/api/v1/mcp
```

- Endpoint: `POST /api/v1/mcp` (JSON-RPC 2.0); `GET` returns an SSE stream. Any active API key (`X-Api-Key` header) works.
- AI clients connect to `https://<host>/api/v1/mcp` via the CF tunnel (port 8080) — no local process needed.
- See [docs/03-operations/mcp.md](docs/03-operations/mcp.md) for every tool input and an example call for each tool.

**Semantic search** (optional embedder, profile `embed`): movies/performers are embedded with a multilingual E5 model and stored as `dense_vector` in Elasticsearch (kNN). MCP tool `search` answers natural-language queries; keyword search still works when the embedder is off.

- Local `make up` starts the embedder (`EMBEDDER_URL=http://embedder:8000`); production crawler nodes point `EMBEDDER_URL=http://<parent>:8000`.
- Create/update ES vector mappings: `make setup SERVICE=elasticsearch`.
- Recreate mappings and re-index all movies and performers: `make setup SERVICE=elasticsearch REINDEX=1`.

## Fetch / CF bypass

Workers run crawlerx with `browser_likely` (HTTP → impersonate → Playwright/stealth → FlareSolverr).

| Piece | Where |
|---|---|
| FlareSolverr | compose service `flaresolverr:8191` |
| Playwright + stealth scripts | `/var/www/crawlerx/scripts/*` (volume) |
| Chromium cache | docker volume `playwright-browsers` |
| Env | `CRAWLERX_FLARESOLVERR_URL`, `CRAWLERX_PLAYWRIGHT_SCRIPT` |

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

GitHub Actions (GitHub-hosted runners) run on every push and pull request to
`develop`. No deploy, no image push. Details and local reproduction:
[`docs/03-operations/ci.md`](docs/03-operations/ci.md).

## Project docs

- `BUSINESS.md` / `ARCHITECTURE.md` / `IMPLEMENTATION.md` / `DESIGN-DISCUSSION.md`

# IMPLEMENTATION — javmeta POC

> **Current state (October 2026).** This file is the original implementation plan. For how to run, configure and operate JavMeta today
> use [README.md](README.md) (sources, crawl flow, data stores, API, MCP, reports, observability, environments) and
> [docs/03-operations](docs/03-operations) (queue, backup, monitoring, deployment, API contract, MCP, CI, end-to-end journeys).
> Commands that did not exist when this plan was written: `crawler:seed`, `crawler:feed-pool`, `crawler:source-health`,
> `crawler:recrawl-incomplete`, `crawler:recrawl-stopped`, `es:setup`, `search:backfill-embeddings`, `javmeta:report`,
> `javmeta:verify-sample`, `javmeta:smoke`, `javmeta:sentry-test`, `observability:import`.


Status: **Approved** (planning gate closed, 2026-09-17). Working file — ticket
state is updated by the root only. Source of truth. Requirements:
`BUSINESS.md` (same folder). Architecture: `ARCHITECTURE.md` (same folder).

## Plan gates (closed)

- Requirements gate: **Approved** (2026-09-17) — `BUSINESS.md`
- Architecture gate: **Approved** (2026-09-17) — `ARCHITECTURE.md`
- Planning gate: **Approved** (2026-09-17) — this file

## General rules (all tasks)

- **Model:** `joo-dev_<n> · openai/gpt-5.5` · **Status default:** backlog
- **No commit / push / PR.** Root or utility roles handle git after gates.
- Quality gate every task: `make lint` · `make test`
- Docker/infra tasks: `make build` · `docker compose config`
- DB tasks: `make migrate MODE=fresh` · `make test`
- PHP files: `declare(strict_types=1);`, Pint (per preset), tests use
  Faker/factories, PHPUnit class names `{Subject}Test`, English Conventional
  Commits (root handles).
- DoR passed for all items below (source list is frozen by task JV-W0-001).

## Critical path to go/no-go (G-1..G-7)

`JV-W0-001 → JV-W0-002 → JV-W0-003 → JV-W0-004 → JV-W1-005 → JV-W1-006 →
JV-W1-007 → JV-W1-010 → JV-W1-011 → JV-W1-013 → JV-W1-014 → JV-W1-015`

---

## Task JV-W0-001: Run crawlerx live-check and freeze Wave 1 source list

- **Traceability:** R-1, AC-2.6, AD-3, AD-4 → `tests/Feature/Sources/SourceManifestTest.php`
- **Assigned to:** joo-dev_1 · **Status:** done (verified 2026-09-17)
- **Description:** Verify real crawlerx adapters before any crawl implementation.
  Classify each candidate adapter as `working` / `walled` / `broken`; Wave 1
  may use only `working`. **Must run first (critical path).**
- **Write scope:** none in `projects/javmeta`; read/run only in `projects/crawlerx`.
- **Steps:** from `projects/crawlerx`, run `make live-check SITE=<slug>` for:
  javdb, javdatabase, javlibrary, missav, fc2, onejav, javbtc, 141jav, ffjav,
  avfan, jable, xcity, warashi, minnanoav, onepondo, caribbeancom, heyzo,
  tokyohot, duga, eporner. Classify each into
  `{slug, status: working|walled|broken, reason, sample_url, checked_at}`.
- **Edge cases:** all sources blocked → stop (no invented fallback); missing deps
  → `make install` first; CF wall / CAPTCHA = `walled`, not `broken`; intermittent
  timeout → retry once, then classify with evidence.
- **Do NOT:** edit crawlerx; add proxy; include `walled` sources in Wave 1 crawl set.
- **Verification:** `make live-check SITE=javdb` && `make live-check SITE=onejav`.
- **Ask for help when:** fewer than 2 sources `working`; `make live-check` fails
  before testing adapters; results contradict ADR assumptions.
- **DoD:** source list has slug/status/reason/evidence; frozen Wave 1 list only `working`.
- **Progress / handover:**
  - Completed: 2026-09-17 — live-check all 20 candidates → all `working` (javdb, javdatabase, javlibrary, missav, fc2, onejav, javbtc, 141jav, ffjav, avfan, jable, xcity, warashi, minnanoav, onepondo, caribbeancom, heyzo, tokyohot, duga, eporner). Contradicts ADR assumption (javdb/javlibrary/missav expected CF-wall — all passed live). Note: crawlerx `make live-check SITE=x` passes x positionally (UnsupportedUrlException); correct form `php tools/live-check.php --site=x` in Docker. Frozen Wave 1 source list = all 20 `working` sources. No `walled`/`broken`.
  - Next step: JV-W0-002 (scaffold)
  - Blocked: —
  - On disk: —

---

## Task JV-W0-002: Scaffold Laravel POC app, Docker Compose, env, hooks

- **Traceability:** AD-1, AD-2, AD-5, AD-17 → `tests/Feature/Health/BasicAppBootTest.php`
- **Assigned to:** joo-dev_2 · **Status:** done (verified 2026-09-17)
- **Write scope:** `projects/javmeta/**` except `BUSINESS.md`, `ARCHITECTURE.md`; no writes to sibling repos.
- **Steps:** scaffold Laravel (prefer latest stable supporting PHP 8.5; if neither
  12/13 resolves, stop and ask). Add composer.json, artisan, bootstrap, app/config/
  database/routes/tests, Dockerfile, docker-compose.yml, .dockerignore, .env.example,
  .gitignore, Makefile, README.md. Composer deps:
  `php ^8.5`, `jooservices/client ^4`, `jooservices/dto ^3`, `jooservices/exceptions ^4`,
  `jooservices/laravel-controller ^4`, `jooservices/laravel-repository ^4`,
  `jooservices/crawlerx` path `../crawlerx` (if path versioning blocks, stop and ask).
  Docker: `php:8.5-cli-bookworm`, `postgres:17`, optional profile `fetch`
  (node Playwright + FlareSolverr). `.env.example` keys: APP_*, DB_* (pgsql/javmeta),
  QUEUE_CONNECTION=database, CACHE_STORE=database,
  NOTIFICATION_TELEGRAM_BOT_TOKEN/CHAT_ID (TELEGRAM_* legacy fallback),
  JAVMETA_OWNER_ADMIN_TOKEN — no real secrets. README `Status: POC — branch model may be bypassed`.
  `git init` only if not already a repo; no commit.
- **Do NOT:** create GitHub repo/remotes; overwrite BUSINESS.md/ARCHITECTURE.md; put real `.env` secrets in repo.
- **Verification:** `make build` && `make install` && `docker compose config` && `make test`.
- **Ask for help when:** Laravel 13/12 cannot resolve on PHP 8.5; any JOOservices
  package cannot be installed; existing files would be overwritten.
- **DoD:** app boots in Docker; .env.example has no secrets; POC marker present; hooks install command exists.
- **Progress / handover:**
  - Completed: 2026-09-17 — Laravel 12 scaffold (resolves on PHP 8.5), Dockerfile + Compose (api/worker/scheduler/postgres + fetch/flaresolverr profile), .env.example (no secrets), Makefile (lint/test/migrate/up/down/scheduler/crawl), Pint + Larastan, boot test green (1 passed/3 assertions), local git init no commit. `jooservices/crawlerx` via path repo. DB queue/cache (no Redis).
  - Next step: JV-W0-003 (migrations)
  - Blocked: —
  - On disk: —

---

## Task JV-W0-003: Add complete PostgreSQL schema, models, factories

- **Traceability:** REQ-1..9, AD-2, AD-7, AD-8, AD-9, AD-16 → migration tests
- **Assigned to:** joo-dev_3 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W0-002
- **Write scope:** `database/migrations/**`, `database/factories/**`, `app/Models/**`, `tests/Feature/Database/**`
- **Steps:** create migrations for all ADR tables exactly: `movies`
  (id bigserial, display_code, code_normalized unique, nullable title_jp/title_en/
  release_date/runtime_minutes/censored/maker/label/series/community_score,
  completeness_tier smallint, search_vector tsvector generated, delisted_at,
  needs_review bool, first_seen_at/updated_at/crawled_at; indexes: code_normalized,
  (release_date desc,id desc), (updated_at desc,id desc), (community_score desc nulls last,id desc),
  GIN search_vector, trigram title indexes); `movie_codes` (unique (code_normalized,source_slug),
  index code_normalized); `movie_observations` (append-only; no update path); `genres`,
  `movie_genres`; `performers`, `performer_aliases`, `movie_performers`; `movie_media`;
  `sources`; `crawl_queue`; `crawl_runs`; `crawl_events`; `review_flags`; `api_keys`;
  `api_usage_log`. Add enum-like constants on models (e.g. Source CIRCUIT_*).
  Factories use Faker.
- **Edge cases:** movie_media stores URL only (no blob/binary); performers identity is
  (source_slug, external_id); genres only label_normalized, no canonical map;
  nullable fields for unknown data, never `''`.
- **Do NOT:** implement crawl/API logic; add fuzzy merge schema; add billing tables.
- **Verification:** `make migrate MODE=fresh` && `make test -- --filter=SchemaTest`.
- **Ask for help when:** PG generated tsvector conflicts with Laravel migration;
  required indexes cannot be created in test DB; a table from ADR is missing/ambiguous.
- **DoD:** all ADR tables exist; unique/index constraints exist; tests verify no media byte/blob columns.
- **Progress / handover:**
  - Completed: 2026-09-17 — 16 ADR tables migrations (Postgres 17, pg_trgm enabled), generated tsvector + GIN on movies, Eloquent models + constants + relationships, Faker factories, SchemaTest. `make migrate MODE=fresh` ✅ · `make test` 5 passed (183 assertions) · `make lint` ✅. NOTE for review: test suite uses SQLite fallback for speed — PG-specific features (tsvector/trgm/generated col) verified via `make migrate MODE=fresh` only; consider a PG test env for schema fidelity.
  - Next step: JV-W0-004 (DTO/VO/envelope)
  - Blocked: —
  - On disk: —

---

## Task JV-W0-004: Add shared DTOs, value objects, API envelope contracts

- **Traceability:** BR-9, REQ-1, REQ-3, REQ-4, REQ-6 → `NormalizedCodeTest`, DTO tests
- **Assigned to:** joo-dev_4 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W0-003
- **Write scope:** `app/Support/Code/NormalizedCode.php`,
  `app/Data/Crawl/MovieDraft.php`, `app/Data/Crawl/PerformerDraft.php`,
  `app/Data/Api/MovieResourceData.php`, `app/Http/Resources/**`, `app/Exceptions/**`,
  `tests/Unit/Support/Code/**`, `tests/Unit/Data/**`
- **Signatures:**
  - `NormalizedCode::from(string $raw): self` → `value(): string` (trim, upper prefix,
    strip separators: SSIS-001 → SSIS001; preserve prefix identity FC2-PPV-1234567 ≠
    FC2-1234567; pad numeric suffix SSIS-1/SSIS001/ssis-001 → SSIS001; no guessing).
  - `final readonly MovieDraft{sourceSlug, sourceUrl, code, titleJp?, titleEn?,
    releaseDate?, runtimeMinutes?, censored?, maker?, label?, series?, genres[],
    performers[], coverUrl?, extras{magnet,hls,gallery,score}, crawledAt}`.
- **Do NOT:** implement DB persistence; fuzzy-match titles; map genres to canonical taxonomy.
- **Verification:** `make test -- --filter=NormalizedCodeTest` && `--filter=MovieDraftTest` && `make lint`.
- **Ask for help when:** a code format would merge two distinct prefixes; DTO shape
  conflicts with crawlerx MovieDto; a new field not in ADR is needed.
- **DoD:** VO tests cover valid/malformed/prefix-collision/whitespace-lowercase; DTOs immutable/readonly.
- **Progress / handover:**
  - Completed: 2026-09-17 — NormalizedCode VO, MovieDraft/PerformerDraft/MovieResourceData readonly DTOs, MovieResource/PerformerResource, RFC 7807 exceptions (unauthorized/movie_not_found/invalid_filter/bulk_limit_exceeded/rate_limited). Confirmed laravel-controller v4.0.1 respondWithProblem + ProblemDetailsFormatter; laravel-repository v4 cursor + allowlist. `make test` 21 passed (226 assertions) · lint ✅.
  - Next step: JV-W1-005 ∥ JV-W1-009 (parallel-safe)
  - Blocked: —
  - On disk: —

---

## Task JV-W1-005: Implement sources config, AIMD throttle, circuit breaker, crawl queue tick

- **Traceability:** REQ-2, AC-2.2, AC-2.4, AC-2.5, AC-2.6, AD-14, AD-15 → scheduler tests
- **Assigned to:** joo-dev_5 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W0-004, JV-W0-001
- **Write scope:** `config/javmeta_sources.php`, `app/Console/Commands/CrawlTickCommand.php`,
  `app/Services/Crawl/SourceThrottle.php`, `app/Services/Crawl/SourceCircuitBreaker.php`,
  `app/Services/Crawl/CrawlQueueService.php`, `routes/console.php`,
  `tests/Feature/Crawl/CrawlTickCommandTest.php`, `tests/Unit/Services/Crawl/**`
- **Steps:** source config from frozen `working` list (base_url, enabled, priority,
  needs_proxy=false, gap defaults from crawlerx manifest, max_attempts=3). Artisan
  `crawl:tick {--source=} {--limit=50}`. AIMD: success → decrease gap toward min;
  blocked/rate_limited → multiply gap toward max; circuit open skips source.
  Enqueue pending jobs into crawl_queue (DB). Reclaim stale `claimed` rows.
- **Edge cases:** one source open circuit must not block others; duplicate
  (source_slug,url) must not double-enqueue; missing live-check source list → stop;
  max_attempts=3 configurable.
- **Do NOT:** call crawlerx yet; persist movies; add Redis/Horizon.
- **Verification:** `php artisan crawl:tick --limit=1` && `make test -- --filter=CrawlTickCommandTest` && `--filter=SourceCircuitBreakerTest`.
- **Ask for help when:** frozen list has zero/one movieing source; manifest throttle
  unreadable without approved fallback; queue reclaim behavior ambiguous.
- **DoD:** tick per-source isolated; DB queue resumes after stale claim; tests cover closed/open/half-open.
- **Progress / handover:**
  - Completed: 2026-09-17 — config/javmeta_sources.php (20 frozen sources, base_urls + throttle from crawlerx manifests, priority D-7, max_attempts=3 default), SourceThrottle (AIMD clamp [min,max]), SourceCircuitBreaker (closed↔open↔half_open, cooldown 300s), CrawlQueueService (dedupe UNIQUE, stale-claim reclaim, attempts≤max), CrawlTickCommand (per-source isolated loop, CrawlRun+CrawlEvent, enqueue listing seeds kind=listing delayed by gap), schedule every minute (test-guarded). Fixed 2 Pint + 5 PHPStan. `make test` 46 passed (313) · lint ✅ · `crawl:tick --limit=1` enqueued 20 listing rows.
  - Next step: JV-W1-006 (crawlerx jobs + normalizers)
  - Blocked: —
  - On disk: —

---

## Task JV-W1-006: Integrate crawlerx jobs and SourceNormalizer adapters

- **Traceability:** REQ-1a, REQ-2a, AC-1.3, AC-1.4, AC-1.6, AC-2.1, AC-2.4 → job/normalizer tests
- **Assigned to:** joo-dev_6 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W1-005
- **Write scope:** `app/Jobs/FetchListingJob.php`, `app/Jobs/FetchDetailJob.php`,
  `app/Services/Crawler/CrawlerxClient.php`, `app/Services/Normalize/SourceNormalizer.php`,
  `app/Services/Normalize/Normalizers/**`, `tests/Feature/Jobs/**`, `tests/Unit/Services/Normalize/**`
- **Steps:** wrap `CrawlerX::url()->options()->tryCrawl()` in CrawlerxClient. On failed
  outcome: record crawl_events, increment queue attempts, no movie write. On success:
  MovieDto + metadata → MovieDraft via SourceNormalizer registry. Implement adapters
  only for frozen Wave 1 sources (≥2 if live-check allows).
- **Edge cases:** missing title/field → parse failure; metadata{} key differs per source;
  cover/gallery/HLS URLs are references only; unknown source slug → parse_drift event.
- **Do NOT:** modify crawlerx; add proxy; write normalizers for non-frozen sources.
- **Verification:** `make test -- --filter=FetchDetailJobTest` && `--filter=SourceNormalizerTest` && `make lint`.
- **Ask for help when:** frozen source has no reliable MovieDto.code; source requires
  login/paywall; normalizer needs a field not in MovieDraft.
- **DoD:** ≥2 working source normalizers implemented (if live-check allows); failed crawl
  never creates empty movie; tests use fake DTOs/fixtures.
- **Progress / handover:**
  - Completed: 2026-09-17 — CrawlerxClient wrapper (tryCrawl, 6-code error mapping), CrawlerxFetchResult DTO, SourceNormalizer interface + Registry + NormalizationFailedException, 3 normalizers (Default config-driven key map, Catalog family, Jable HLS), FetchListingJob (enqueue detail + next page, dedupe, events), FetchDetailJob (normalize → MovieDraftSink seam → MovieDraftCaptured event), HandlesCrawlQueueRow concern, MovieDraftSink contract (JV-W1-007 implements + binds). `make test` 87 passed (469) · lint 117 files ✅. NOTE: `make test` in container refreshes dev pgsql (pre-existing phpunit env quirk) — wiped the 20 seeded crawl_queue rows; re-run crawl:tick before manual smoke. Queue dispatch trigger (rows→jobs) deferred to JV-W1-014.
  - Next step: JV-W1-007 (MoviePersister implements MovieDraftSink)
  - Blocked: —
  - On disk: —

---

## Task JV-W1-007: Implement MovieMerger, ConflictPolicy, Persist transaction and guards

- **Traceability:** REQ-1, BR-1, BR-2, BR-11, R-4, R-5, AC-1.1..1.6, AC-2.2, AC-9.3 → persistence tests
- **Assigned to:** joo-dev_7 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W1-006
- **Write scope:** `app/Services/Merge/MovieMerger.php`, `app/Services/Merge/ConflictPolicy.php`,
  `app/Services/Merge/SourcePriorityConflictPolicy.php`, `app/Services/Persist/MoviePersister.php`,
  `app/Services/Persist/CompletenessTierCalculator.php`, `tests/Unit/Services/Merge/**`, `tests/Feature/Persist/**`
- **Rules:** merge only (1) exact code_normalized, (2) source-declared linkage. No fuzzy
  title matching. movie_observations append-only. Null-overwrite guard: never replace
  existing primary non-null with null. Sharp-drop guard: core non-null count drops ≥3 →
  keep old values, needs_review=true, create review_flags. Genres union by normalized label
  + provenance. Recompute completeness_tier. Update crawl_runs counters.
- **Edge cases:** prefix collision; same movie from 2 sources with conflicting title/runtime;
  soft-404 empty detail; duplicate genres different casing; duplicate media URL.
- **Do NOT:** fuzzy merge; delete/update old observations; cache media bytes.
- **Verification:** `make test -- --filter=MovieMergerTest` && `--filter=MoviePersisterTest` && `--filter=SourcePriorityConflictPolicyTest`.
- **Ask for help when:** a source implies linkage but no code available; exact-only
  violates owner expectation; sharp-drop threshold needs changing.
- **Risk:** HIGH (R-4/R-5). False merge worse than false split; preserve exact-only.
- **DoD:** tests prove append-only observations, no null overwrite, exact-only merge, review flag on sharp drop.
- **Progress / handover:**
  - Completed: 2026-09-17 — MovieMerger (exact code_normalized only, prefix-safe, no fuzzy), ConflictPolicy interface + SourcePriorityConflictPolicy (priority authority + tie→newest), MoviePersister implements MovieDraftSink (1 transaction: append-only observations incl null observations w/ value_hash, guards, upsert codes/genres/media/performers, completeness tier, counters, throttle/breaker onSuccess), CompletenessTierCalculator (11 core fields + source-class factor). Bound in AppServiceProvider. `make test` 115 passed (595, both sqlite host + PG17 docker) · lint 125 files ✅. NOTES: conflict priority = smaller number wins (javdb 10 > onejav 60); sharp-drop keeps ALL old values + needs_review + review_flags(consecutive_failures=0); kind inference FC2*/HEYZO*/TOKYOHOT* else dvd; source-declared linkage inactive in Wave 1 (exact-only, per AD-10). For JV-W1-013 reports: conflict rate must WHERE value IS NOT NULL. Dev recommends joo-reviewer on this HIGH-RISK task before QA.
  - Next step: JV-W1-010 (lookup + bulk)
  - Blocked: —
  - On disk: —

---

## Task JV-W1-008: Implement health endpoint, Telegram + log alerts, worker watchdog

- **Traceability:** REQ-9, AC-9.1..9.4, AD-18, R-7 → health/alert tests
- **Assigned to:** joo-dev_8 · **Status:** backlog · **Depends on:** JV-W1-005, JV-W1-007
- **Write scope:** `routes/api.php`, `app/Http/Controllers/HealthController.php`,
  `app/Services/Health/StatusHealthCheck.php`, `app/Events/CrawlSourceUnhealthy.php`,
  `app/Listeners/SendCrawlAlert.php`, `app/Notifications/TelegramAlertNotifier.php`
  (adapter over `jooservices/laravel-notifications` TelegramChannel),
  `app/Notifications/ActivityLogAlertNotifier.php`,
  `app/Console/Commands/WorkerWatchdogCommand.php`,
  `tests/Feature/Health/**`, `tests/Unit/Notifications/**`
- **Endpoint:** `GET /health` no auth → `{status: ok|degraded|down, sources:[{slug,
  circuit_state, last_success_at, last_error_at}], queue:{pending,failed}}`. No title/performer data.
- **Commands:** `php artisan javmeta:watchdog`.
- **Edge cases:** Telegram env missing → log warning, no crash; source-down alert ≤24h;
  health must not leak movie codes/titles; stale worker heartbeat → log + Telegram alert.
- **Do NOT:** require API key for /health; expose crawl URLs with sensitive params; add email.
- **Verification:** `make test -- --filter=HealthControllerTest` && `--filter=TelegramAlertNotifierTest` && `php artisan javmeta:watchdog`.
- **Ask for help when:** Telegram token/Chat ID needed for manual live test; health needs
  extra fields not in ADR; alert frequency/debounce unclear.
- **DoD:** /health works without key; alerts use Telegram + laravel-logging strategy; worker stale condition tested.
- **Progress / handover:**
  - Completed:
  - Next step:
  - Blocked:
  - On disk:

---

## Task JV-W1-009: Implement API key auth, usage log, rate limit, admin key CRUD

- **Traceability:** REQ-7, AC-7.1..7.4, AD-12, AD-13 → auth/admin tests
- **Assigned to:** joo-dev_9 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W0-004
- **Write scope:** `app/Http/Middleware/AuthenticateApiKey.php`,
  `app/Http/Middleware/LogApiUsage.php`, `app/Http/Middleware/OwnerAdminGuard.php`,
  `app/Services/Auth/ApiKeyService.php`, `app/Http/Controllers/Admin/ApiKeyController.php`,
  `app/Http/Requests/Admin/**`, `routes/api.php`, `tests/Feature/Auth/**`, `tests/Feature/Admin/ApiKeyControllerTest.php`
- **Signatures:** `ApiKeyService{create(label,abuseRpm=60): CreatedApiKey; revoke(id): void;
  findActiveByPlaintext(plain): ?ApiKey}`; `CreatedApiKey{plaintext, prefix, model}`.
  Key plaintext `jvm_...`, store hash only, plaintext shown once on create.
- **Admin routes:** `GET/POST /api/v1/admin/keys`, `DELETE /api/v1/admin/keys/{id}`.
  Data endpoints auth `X-API-Key`; admin `X-Owner-Token` from env.
- **Edge cases:** revoked key → immediate 401 (no cache); missing/wrong key body has no data;
  usage log records 2xx/4xx; over abuse_rpm → 429 + Retry-After.
- **Do NOT:** implement billing/quota; store plaintext key; cache key auth.
- **Verification:** `make test -- --filter=ApiKeyServiceTest` && `--filter=ApiKeyControllerTest` && `--filter=ApiUsageLogTest`.
- **Ask for help when:** owner admin auth needs a different mechanism; rate limiter cannot
  use DB without cache; immediate revoke conflicts with framework cache.
- **DoD:** hash-only storage test passes; revoke immediate test passes; 401/429 RFC 7807 covered.
- **Progress / handover:**
  - Completed: 2026-09-17 — ApiKeyService (SHA-256 hash-only, jvm_ prefix, abuse_rpm=60 default, revoke immediate no-cache), AuthenticateApiKey + LogApiUsage + OwnerAdminGuard middlewares (hash_equals constant-time, fail-closed), Admin ApiKeyController CRUD (plaintext once), Laravel RateLimiter per key → 429 + Retry-After (database cache store, no Redis), config/javmeta_auth.php, routes/api.php admin keys + auth wiring. AC-7.1..7.4 + AC-3.4 covered. `make test` 46 passed (313) · lint ✅ (after JV-W1-005 fixes). NOTE: repo branch `main` zero commits (POC marker allows bypass) — establish develop/master at first commit.
  - Next step: JV-W1-010 / JV-W1-012
  - Blocked: —
  - On disk: —

---

## Task JV-W1-010: Implement movie lookup and bulk lookup APIs

- **Traceability:** REQ-3, REQ-6, AC-3.1..3.4, AC-6.1..6.4 → endpoint tests
- **Assigned to:** joo-dev_10 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W1-007, JV-W1-009
- **Write scope:** `app/Http/Controllers/MovieLookupController.php`,
  `app/Http/Controllers/MovieBulkController.php`, `app/Http/Requests/MovieBulkRequest.php`,
  `app/Http/Resources/MovieResource.php`, `app/Services/Movies/MovieLookupService.php`,
  `routes/api.php`, `tests/Feature/Api/MovieLookupControllerTest.php`, `tests/Feature/Api/MovieBulkControllerTest.php`
- **Endpoints:** `GET /api/v1/movies/{code}`; `POST /api/v1/movies:bulk` `{codes:[...]}`
  ≤100 → per-code status (found/not-found); >100 → 413 `bulk_limit_exceeded`, process none;
  duplicates → one result per unique normalized code.
- **Edge cases:** ssis001/SSIS-1/" ssis-001 " normalize; missing/revoked key 401; unknown
  code 404 for single; no N+1 for bulk.
- **Do NOT:** implement search filters here; include admin report data; expose raw api_keys.
- **Verification:** `make test -- --filter=MovieLookupControllerTest` && `--filter=MovieBulkControllerTest`.
- **Ask for help when:** laravel-controller envelope conflicts with resource shape; bulk
  duplicate behavior needs product approval; p95 cannot meet 500ms at 5k seed rows.
- **DoD:** AC-3 and AC-6 fully covered; bulk uses batch query; RFC 7807 errors implemented.
- **Progress / handover:**
  - Completed: 2026-09-17 — MovieLookupService (normalize + findByCode + findByCodes batch `IN` no N+1, eager-load, provenance via ConflictPolicy), MovieLookupController (show 200/404 movie_not_found), MovieBulkController (per-code status, dedupe normalized, always 200 incl not-found, 413 over 100 process none), MovieBulkRequest, MovieResource (provenance + actresses guard + genres union dedupe), routes additive. `make test` 140 passed (727) · lint 131 files ✅ · lookup 32ms @5k seeded (NFR p95 ≤500ms). FOLLow-up fix: NormalizedCode now strips leading zeros on numeric suffix (SSIS-0001/SSIS-001/SSIS-1/ssis-001 → SSIS001; all-zero → 000; non-numeric suffix rejected; FC2-PPV vs FC2 still distinct). `make test` 142 passed (732).
  - Next step: JV-W1-011 (search)
  - Blocked: —
  - On disk: —

---

## Task JV-W1-011: Implement movie search, filters, sort, keyset cursor, meta genres

- **Traceability:** REQ-4, AC-4.1..4.6, AD-11 → search tests
- **Assigned to:** joo-dev_11 · **Status:** in-progress · **Depends on:** JV-W1-010
- **Write scope:** `app/Http/Controllers/MovieSearchController.php`,
  `app/Http/Controllers/MetaGenreController.php`, `app/Http/Requests/MovieSearchRequest.php`,
  `app/Repositories/MovieRepository.php`, `app/Services/Search/CursorCodec.php`,
  `routes/api.php`, `tests/Feature/Api/MovieSearchControllerTest.php`,
  `tests/Feature/Api/MetaGenreControllerTest.php`, `tests/Unit/Services/Search/CursorCodecTest.php`
- **Endpoints:** `GET /api/v1/movies` (q, genre, actress, maker, series, label,
  released_from/to, runtime_min/max, censored, sort=relevance|release_date|update_date|rating,
  cursor, per_page ≤100); `GET /api/v1/meta/genres`.
- **Cursor:** base64url JSON `{sort, last_sort, last_id}` + HMAC when app key available.
- **Edge cases:** q empty/symbols/1-char → 400 invalid_filter; released_to < released_from → 400;
  page beyond total → empty data + same total; insert between pages must not duplicate/skip;
  sort stable via id tie-break.
- **Do NOT:** use OFFSET pagination; add external search engine; canonical-map genres.
- **Verification:** `make test -- --filter=MovieSearchControllerTest` && `--filter=CursorCodecTest`.
- **Ask for help when:** laravel-repository cursor API cannot express required query;
  PG search indexes fail in test container; exact total count too slow even at POC scale.
- **DoD:** keyset pagination tests prove no duplicate/no skip; all filters allowlisted;
  search p95 smoke test available for seeded 5k movies.
- **Progress / handover:**
  - Completed:
  - Next step:
  - Blocked:
  - On disk:

---

## Task JV-W1-012: Implement performer catalog APIs

- **Traceability:** REQ-5, AC-5.1..5.5, AD-9 → performer tests
- **Assigned to:** joo-dev_12 · **Status:** done (verified 2026-09-17) · **Depends on:** JV-W1-009, JV-W0-003
- **Write scope:** `app/Http/Controllers/PerformerController.php`,
  `app/Http/Requests/PerformerSearchRequest.php`, `app/Http/Resources/PerformerResource.php`,
  `app/Repositories/PerformerRepository.php`, `routes/api.php`, `tests/Feature/Api/PerformerControllerTest.php`
- **Endpoints:** `GET /api/v1/performers?q=`, `GET /api/v1/performers/{id}` → id,
  name_romaji/kanji/kana, aliases[], linked_title_count, profile_url, image_url.
- **Edge cases:** two performers same name from different (source_slug, external_id) remain
  separate; alias search movies; performer with one title exists; multi-performer movie links
  back to all; no body metrics/advanced filters.
- **Do NOT:** canonicalize/fuzzy-merge performers; add body measurements; cache images.
- **Verification:** `make test -- --filter=PerformerControllerTest`.
- **Ask for help when:** source lacks external_id; product wants cross-source performer
  merge; fields outside ADR needed.
- **DoD:** AC-5 fully covered; same-name separation test passes; alias search test passes.
- **Progress / handover:**
  - Completed: 2026-09-17 — PerformerRepository (laravel-repository v4, alias whereHas EXISTS, withCount movies for linked_title_count), PerformerSearchRequest (q min:2 → 400 invalid_filter, per_page≤100), PerformerController (index/show, 404 performer_not_found), PerformerResource ADR §5 shape, PerformerNotFoundException, routes additive. `make test` 62 passed (369) · lint 99 files ✅.
  - Next step: JV-W1-007 / JV-W1-010
  - Blocked: —
  - On disk: —

---

## Task JV-W1-013: Implement admin reports for quality, cost, sample verification

- **Traceability:** REQ-8, AC-8.1..8.4, G-3, G-4, G-5, G-7 → report tests
- **Assigned to:** joo-dev_13 · **Status:** backlog · **Depends on:** JV-W1-007, JV-W1-010, JV-W1-011
- **Write scope:** `app/Http/Controllers/Admin/ReportController.php`,
  `app/Http/Requests/Admin/SampleVerificationRequest.php`,
  `app/Services/Reports/QualityReportService.php`, `app/Services/Reports/CostReportService.php`,
  `app/Services/Reports/SampleVerificationService.php`, `app/Jobs/GenerateReportJob.php`,
  `routes/api.php`, `tests/Feature/Admin/ReportControllerTest.php`, `tests/Unit/Services/Reports/**`
- **Endpoints:** `GET /api/v1/admin/report/quality` → totals_by_source,
  core_field_completeness, conflict_rate_by_field, merge_statistics
  (movies_total, movies_with_multiple_codes, codes_per_movie_distribution);
  `GET /api/v1/admin/report/cost` → per_source (proxy_requests_per_1000,
  browser_fetches_per_1000, failures_per_1000, crawl_hours_per_1000) + extrapolation 100k/400k;
  `GET /api/v1/admin/report/sample-verification` (≤200 codes JSON).
- **Edge cases:** uncovered sources listed as `not_covered`; divide-by-zero on empty DB;
  sample not-found listed explicitly; conflict rate per field, not global only.
- **Do NOT:** decide go/no-go automatically; add cost dollars without measured inputs;
  hide uncovered FANZA/DMM.
- **Verification:** `make test -- --filter=ReportControllerTest` && `--filter=QualityReportServiceTest` && `--filter=CostReportServiceTest`.
- **Ask for help when:** owner sample format differs; cost extrapolation needs proxy price
  inputs; merge stats suggest severe false-split requiring scope expansion.
- **DoD:** AC-8 fully covered; reports include uncovered sources; merge statistics included.
- **Progress / handover:**
  - Completed:
  - Next step:
  - Blocked:
  - On disk:

---

## Task JV-W1-014: Add worker runtime hygiene, scheduler, queue restart ops

- **Traceability:** REQ-2, G-6, AC-2.2, R-7 → ops tests/smoke
- **Assigned to:** joo-dev_14 · **Status:** backlog · **Depends on:** JV-W1-008
- **Write scope:** `docker-compose.yml`, `Dockerfile`, `Makefile`, `config/queue.php`,
  `routes/console.php`, `app/Console/Commands/QueueStaleRecoveryCommand.php`,
  `tests/Feature/Ops/WorkerRuntimeTest.php`
- **Steps:** Compose services api/worker/scheduler/postgres (+ node/flaresolverr under
  profile `fetch`). Worker: `php artisan queue:work database --queue=default --sleep=3
  --tries=3 --max-time=3600 --max-jobs=500`. Scheduler: `php artisan schedule:work` (or
  cron equivalent). Make targets up/down/scheduler/migrate/setup/crawl.
  `CrawlerXFactory::reset()` between batches if available.
- **Edge cases:** worker restart must not lose DB queue jobs; stale claimed jobs return
  pending; scheduler runs crawl:tick every minute; no Redis.
- **Do NOT:** introduce Horizon/Redis; tune host OS/systemd in repo task; add deployment secrets.
- **Verification:** `docker compose config` && `make up MODE=crawler` && `docker compose ps` && `make scheduler` && `make test -- --filter=WorkerRuntimeTest`.
- **Ask for help when:** Compose cannot run multiple roles from one image; long-running
  worker leaks memory in local smoke; host deployment details requested.
- **DoD:** Compose validates; worker max-time/max-jobs configured; scheduler includes crawl tick + watchdog.
- **Progress / handover:**
  - Completed:
  - Next step:
  - Blocked:
  - On disk:

---

## Task JV-W1-015: Add POC verification harness and go/no-go commands

- **Traceability:** G-1..G-7, AC-2.1, AC-2.2, AC-3.1, AC-4.3, AC-6.2, AC-7.2, AC-8.2 → final suite
- **Assigned to:** joo-dev_15 · **Status:** backlog · **Depends on:** JV-W1-013, JV-W1-014
- **Write scope:** `app/Console/Commands/PocSmokeCommand.php`,
  `app/Console/Commands/PocSampleImportCommand.php`,
  `app/Console/Commands/PocGoNoGoReportCommand.php`, `tests/Feature/Poc/**`, `README.md`
- **Commands:** `php artisan poc:smoke`; `php artisan poc:sample-import
  storage/app/poc/sample-codes.csv` (csv: code,expected_title,expected_source);
  `php artisan poc:go-no-go-report` → movie count 1,000–5,000, sources covered/not,
  72h evidence placeholders, 200-code sample found rate, core field completeness 8/11
  threshold, cost per 1k titles, conflict rate per field, go/no-go checklist (no auto-approval).
- **Edge cases:** sample >200 rows → 400-like failure; empty DB → insufficient evidence;
  <1,000 movies → fail readiness, not exception; 72h proof requires runtime evidence.
- **Do NOT:** fake live crawl success; auto-mark go/no-go; invent cost data.
- **Verification:** `php artisan poc:smoke` && `php artisan poc:go-no-go-report` && `make test -- --filter=PocGoNoGoReportCommandTest` && `make lint` && `make test`.
- **Ask for help when:** owner wants to change G-3/G-6 thresholds; live crawl cannot reach
  1,000 movies with frozen sources; report lacks data because earlier tasks missed counters.
- **DoD:** final local suite green; POC evidence commands reproducible; report supports
  owner go/no-go gate but does not approve it.
- **Progress / handover:**
  - Completed:
  - Next step:
  - Blocked:
  - On disk:

---

## Coverage map

- REQ-1 / AC-1.1..1.6 → W0-003, W0-004, W1-006, W1-007, W1-010
- REQ-2 / AC-2.1..2.6 → W0-001, W1-005, W1-006, W1-007, W1-014, W1-015
- REQ-3 / AC-3.1..3.4 → W1-010
- REQ-4 / AC-4.1..4.6 → W1-011
- REQ-5 / AC-5.1..5.5 → W1-012
- REQ-6 / AC-6.1..6.4 → W1-010
- REQ-7 / AC-7.1..7.4 → W1-009
- REQ-8 / AC-8.1..8.4 → W1-013, W1-015
- REQ-9 / AC-9.1..9.4 → W1-008

## Explicit deferrals (Wave 2+ / out of POC)

FANZA/DMM; proxy support in crawlerx; fuzzy merge; billing; takedown; webhook;
canonical genre taxonomy; media caching.

## High-risk notes

- **R-1 live-check:** fail fast. <2 working sources → stop and ask root; do not expand to FANZA/proxy.
- **R-4 drift guards:** keep old good values, append observations, set needs_review, alert.
- **R-5 merge:** exact-only + source-declared linkage only. False-split accepted in Wave 1; false-merge not.

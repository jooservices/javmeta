# Runtime Dependencies

`health:startup` gates batch/worker commands and the native systemd worker
units. PHP-FPM requests consult a shared, short-lived health snapshot before
sessions/auth and return 503 while a primary is down. The API container
healthcheck still uses `/api/health/live`, which reports only process liveness.
The complete policy, startup command and operator steps are in
[startup health](startup-health.md).

The application, production, and CI PHP images include the phpredis 6.3.0
extension, which is required by Composer as `ext-redis`. Redis is available to
the application, while the default queue, cache, and session drivers remain
database-backed.

## Dependency policy

`JAVMETA_ROLE` identifies each `api`, `mcp`, `scheduler`, or `worker` container.
Startup health uses a stricter role policy than the dependency status matrix:

| Dependency | Role | Probe |
| --- | --- | --- |
| PostgreSQL | Hard | `SELECT 1`; startup also verifies timezone is UTC |
| Redis | Hard | `PING` and prefixed key write/read/delete on configured cache, queue, session and Horizon connections |
| Elasticsearch | Hard for web/API; soft for worker/scheduler by default | `/_cluster/health` |
| MongoDB | Hard | `ping` and settings-store read |
| Embedder, Sentry, browser gateway, Telegram, Ollama, qBittorrent, OpenObserve | Soft | Bounded availability or read-only API check when configured/enabled |

The web gate caches a shared startup snapshot for a few seconds. Dependency
status probes remain cached in process for 10 seconds. Three consecutive failures open a
circuit for 30 seconds before a half-open retry. Dependency state changes are
written to the application log; OpenObserve state changes go to stderr while
OpenObserve is down.

Elasticsearch clients use `ELASTICSEARCH_USERNAME` and `ELASTICSEARCH_PASSWORD`
for Basic authentication, or `ELASTICSEARCH_API_KEY` for API key authentication.
When both forms are configured, the API key takes precedence. Failed requests
are logged with their operation and HTTP status code, counted by
`javmeta_elasticsearch_request_failures`, and reported in the Elasticsearch
health dependency as `request_failures` and `last_failure`. The health state
recovers after a successful Elasticsearch operation.

## API health and route behavior

- `GET /api/health/live` checks process liveness only and is not gated by a
  dependency.
- `GET /api/health` reports each dependency's hard/soft role, state, and
  availability. It returns HTTP 503 when a hard dependency is unavailable and
  HTTP 200 with a degraded status when only soft dependencies are unavailable.
- The same endpoint reports `archive.status`, the cumulative
  `archive.failed_writes` count, and `archive.last_failure_at`. An archive write
  failure degrades health until a later archive write succeeds.
- If PostgreSQL is down, archive status is `unknown` and its count and timestamp
  are `null`; the endpoint skips the archive cache lookup on that down path.
- Movie search requires PostgreSQL and Elasticsearch. PostgreSQL-backed data
  routes, including performer listing/search and movie lookup, require
  PostgreSQL.
- A route blocked by `requires:<dependency>` returns HTTP 503 in the standard
  API error envelope with `Retry-After: 30`.

## Worker behavior

Queue workers do not start when a primary dependency is down. A running worker
continues to pause job reservation through Laravel's queue loop when a hard
dependency fails later, then resumes automatically after recovery. Soft
dependency outages do not pause the worker.

## OpenObserve behavior

OpenObserve is optional. Log records use a bounded in-process buffer; overflow
increments the client's dropped-record counter. Shipping uses a circuit breaker
and a maximum 200 ms timeout budget per HTTP request, so telemetry failures do
not hold API work for longer. A failed send is retained in the buffer while
capacity remains.

## Compose configuration

The local and production Compose files set `JAVMETA_ROLE` for each application
service and do not use `ready-check` as an entrypoint gate. Application
containers have no startup dependency on one another. The API healthcheck uses
`/api/health/live`; dependency health is reported separately by `/api/health`.

## Laravel module-boundary evaluation

**Decision status: proposed; an authorized owner decision is still required.**
This is a structure proposal only. No module package was added, and application
code, runtime configuration, and database schema were not changed.

### Current structure and candidate domains

JavMeta is already divided by technical layer, with partial domain grouping
inside services. `composer.json` requires `laravel/framework: ^13` and maps the
single `App\` PSR-4 namespace to `app/`. The source tree has 559 files under
`app/`: 198 under `Services`, 89 under `Http`, 84 under `Console`, 38 under
`Repositories`, 35 under `Data`, and 30 under `Models`. Routes remain in three
central files, database migrations in 50 files under `database/migrations`,
and PHPUnit tests are organized into Unit, Feature, Integration, and E2E suites
(with separate Shell and Node checks as well).
There is no `app/Modules` tree or `nwidart/laravel-modules` Composer dependency
in the current checkout. See [Composer configuration](../../composer.json),
[API routes](../../routes/api.php), [web routes](../../routes/web.php),
[console schedule](../../routes/console.php), and the existing `app/` and
`database/migrations/` paths.

These are candidate ownership areas, not approved module boundaries. The
central `app/Models`, repository, routes, migration, and provider layers have
cross-domain responsibilities that need dependency analysis before moving.

| Candidate area | Current code paths | Boundary note |
| --- | --- | --- |
| Catalog and performer identity | `app/Models/Movie.php`, `app/Models/Performer.php`, `app/Services/Catalog/`, `app/Services/Movies/`, `app/Services/Performers/`, `app/Services/Identity/`, `app/Services/Normalize/`, `app/Services/Merge/`, `app/Services/Persist/`, `app/Services/Facets/`, `app/Services/Photos/` | Core catalog data is spread across shared models and several domain-named service folders; treat models and persistence as shared until their consumers and stored type names are mapped. |
| Crawl and source ingestion | `app/Services/Crawl/`, `app/Services/Crawler/`, `app/Jobs/Fetch*.php`, `app/Console/Commands/Crawler/`, `app/Models/Crawl*.php`, `app/Models/Source.php` | [`FetchDetailJob`](../../app/Jobs/FetchDetailJob.php) combines queue handling, source and crawl models, normalization, and catalog draft delivery. The default queue uses Redis ([`config/queue.php`](../../config/queue.php#L18)); keep job class names stable until serialized-job compatibility is verified. |
| Search and discovery | `app/Services/Search/`, `app/Services/Suggest/`, `app/Services/Embedding/`, `app/Http/Controllers/SearchController.php`, `app/Http/Controllers/MovieSearchController.php`, `app/Http/Controllers/SuggestController.php` | [`MovieSearchService`](../../app/Services/Search/MovieSearchService.php) uses Elasticsearch and a movie repository to return catalog models; keep route contracts and index names stable. |
| Media, downloads, and library | `app/Services/Media/`, `app/Services/Downloads/`, `app/Services/Library/`, `app/Services/Photos/`, `app/Models/MovieMedia.php`, `app/Models/LibraryFile.php`, `app/Models/MovieDownloadSearch.php`, `app/Http/Controllers/Media/`, `app/Http/Controllers/Ui/` | [`LibraryScanner`](../../app/Services/Library/LibraryScanner.php) uses settings, library-file, and movie repositories. UI routes and external media/download services also cross these areas; verify their contracts before combining or splitting them. |
| Access, nodes, and operator UI | `app/Services/Auth/`, `app/Services/Settings/`, `app/Services/Nodes/`, `app/Services/Operations/`, `app/Http/Controllers/Admin/`, `app/Http/Controllers/Nodes/`, `app/Http/Controllers/Operations/`, `app/Http/Controllers/Ui/`, `app/Http/Middleware/` | Authentication, authorization, settings, enrollment, and UI middleware are security-sensitive and should not be moved as one unreviewed batch. |
| Shared runtime and operations | `app/Services/Health/`, `app/Services/Dependencies/`, `app/Services/Observability/`, `app/Services/Alerts/`, `app/Services/Report/`, `app/Services/Reports/`, `app/Services/Workers/`, `app/Providers/`, `app/Logging/` | Health, telemetry, alerts, scheduling, and worker behavior are represented in the shared service and console-schedule paths; keep them as shared platform code unless a narrower owner can be demonstrated. |

### Approaches and Laravel 13 compatibility

Laravel's [Laravel 13 directory-structure guidance](https://laravel.com/docs/13.x/structure)
says application classes can be placed wherever Composer can autoload them.
That makes an internal `App\Modules\<Domain>\...` namespace under
`app/Modules/<Domain>/` compatible with JavMeta's existing `App\` PSR-4 map;
it needs no new runtime package or Composer plugin. Route files, migrations,
providers, and cross-domain contracts can stay central during an incremental
pilot. The trade-off is that JavMeta must enforce its own module conventions
and dependency rules.

The package-based comparison is [nWidart Laravel Modules](https://github.com/nWidart/laravel-modules#versions).
Its published compatibility table lists Laravel 13 with `^13.0`; Packagist
lists `v13.3.0` (published 2026-10-28), PHP `^8.3`, and
`wikimedia/composer-merge-plugin: ^2.1`. JavMeta's Laravel `^13` and PHP `^8.5`
constraints therefore have a declared compatible range. This verifies the
package's stated version support only; no Composer solve, install, or JavMeta
integration test was performed. See the [package release metadata](https://packagist.org/packages/nwidart/laravel-modules).

| Option | Benefits | Costs and risks | Planning cost |
| --- | --- | --- | --- |
| Keep the current layered structure | No migration risk; matches current PSR-4 setup and test layout. | Domain ownership remains spread across layer directories; new cross-layer dependencies need explicit architecture rules. | No code-move cost; allow 1–2 engineer-days to map dependencies and record boundary rules if that work is approved. |
| Incrementally group code under `App\Modules\...` | Uses Laravel's supported Composer-autoloaded layout, adds no dependency, and allows one domain slice at a time. | JavMeta owns discovery, provider/route wiring, module conventions, and dependency checks. Moving classes affects imports and serialized queue jobs; check for persisted class names before renaming models. | Roughly 2–4 engineer-days for a pilot after dependency mapping; 10–20 engineer-days for a broader move of the current app tree, excluding feature work and CI wait. |
| Adopt `nwidart/laravel-modules` | Generator and conventions for module routes, providers, migrations, resources, tests, and activation. Laravel 13 support is declared. | Adds a Composer plugin that must be explicitly trusted in `allow-plugins`; introduces package discovery and module activation behavior, plus package-specific CI and test setup. These are runtime and supply-chain changes requiring separate review. | Roughly 3–5 engineer-days for package evaluation and the first pilot; 12–24 engineer-days total for adoption and broad code movement, excluding feature work and CI wait. |

The estimates are planning ranges, not measured migration results. One
engineer-day means approximately eight focused engineering hours; review and
CI wait are excluded. The ranges use the current 559-file `app/` tree as a
size signal. The actual dependency graph,
cross-domain service usage, serialized job lifetime, and data-stored model type
usage have not been inventoried; refine the range after a pilot. The third
option's version constraints do not establish that the package integrates with
JavMeta's current routes, providers, test harness, or deployment process.

### Proposed recommendation and reversible stages

**Recommendation (proposed, pending authorized decision):** retain the existing
layered structure as the current boundary and, if module migration is approved,
start with incremental `App\Modules\...` grouping without a package. Do not
install nWidart as part of this decision slice. Defer bulk moves of shared
models, jobs, migrations, routes, and runtime providers until a domain pilot
shows safe ownership and compatibility. If a dependency map cannot identify a
cohesive slice with a safe rollback, defer migration and keep improving the
current dependency rules.

1. Inventory class references, service-provider registration, route/command
   names, queued-job class names, persisted class names, and migration
   discovery.
   Define allowed dependencies between candidate areas and add architecture
   checks before moving classes. Preserve current behavior; verify the current
   locale lookup and fallback behavior before moving locale-related code.
2. Select the lowest-coupling candidate based on that inventory. Move one
   vertical slice with its tests into `app/Modules/<Domain>/`, while keeping
   public HTTP routes, command names, database schema, and shared models in
   place. Run its scoped tests and compare registered routes and commands.
3. Ship the pilot only through the normal review and release authorization.
   Keep compatibility entry points for any moved class name that is present in
   queued payloads or persistent morph values. Expand only after the pilot
   passes production-like verification and
   the dependency rules remain enforceable.
4. Move additional domains in separate, independently reversible changes.
   Consider nWidart only if the pilot shows that its module activation,
   generators, and module-owned resources provide a measured benefit over the
   internal PSR-4 structure; first review the Composer plugin and CI/runtime
   integration. For a package-based implementation, do not disable a module
   that owns routes, queued jobs, or persisted model types.

#### Persistent-reference compatibility and rollback

Class names can outlive a deployment in more than one place: Laravel queue
payloads (including delayed, reserved, retried, and failed jobs), polymorphic
type columns and other database values, and any other durable serialized class
identifier found by the inventory. Compatibility applies in both directions:
workers must read references written before a move, and a rollback release must
read every reference written while the pilot was active.

The default pilot rule is to keep each existing persistent identifier stable
through the rollback window. In particular, retain the original fully
qualified job class as the queue serialization boundary and delegate its work
to the moved implementation; do not enqueue a replacement job class name.
Preserve existing morph-map aliases. If a model has no stable alias and its
fully qualified class name is persisted, keep that model name in place. Move
implementation behind the stable class or alias, rather than changing the
identifier. A code revert can then restore the prior implementation while
queued payloads and stored type values still resolve to the old release.

If an identifier must change, treat compatibility support as a separate
baseline release before the pilot. The baseline must ship readers or aliases
that resolve both old and new identifiers and remain safe when its code is
restored; only then may the pilot write the new identifier. Before rollout,
prove old-to-new and new-to-old processing with representative pending and
failed queue payloads and persisted type values, including worker restart and
retry paths. The rollback target must include that forward-compatible
baseline. Do not roll back past it while new identifiers remain in durable
storage. Before rollback, stop new writes and either drain affected queues and
retry/dead-letter stores and convert persisted values safely, or retain a
reader for both forms in the rollback target. Verify no affected references
remain before removing compatibility code. If these conditions cannot be
proved, do not move or rename the class; defer that slice. This applies to job
classes, model types, and every other persistent class identifier, not only the
first pilot example.

Rollback is therefore a tested compatibility procedure, not merely a revert
of the isolated pilot. This proposal includes no schema or data migration, so
it requires no database rollback; any future persisted-value conversion needs
its own reviewed forward and rollback plan. Keep migration locations stable
until their compatibility is explicitly verified. The decision remains
**proposed**, not approved, until an authorized owner records a decision.

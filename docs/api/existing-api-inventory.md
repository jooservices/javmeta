# Existing production API inventory (javmeta 635e84c, read 2026-11-26)

Source: routes/api.php, routes/web.php, resources/openapi/javmeta.json, app/Services/Mcp/McpToolService.php. **Nothing in this list may disappear or change shape.** The new UI API is a separate major version (`/api/v2`), so the v1 contract and every machine client stay untouched.

Total listed operations: 95.

## Rule

1. Every `/api/*` and `/api/v1/*` operation below stays byte-compatible (paths, parameters, response shapes, status codes, auth). Its response replay test is part of the backend task.
2. `/ui-api`, `/ui-me`, `/ui-admin`, `/ops/*`, `/login`, `/logout`, `/app` keep working for the legacy UI until a sunset date; they only receive `Deprecation`/`Sunset`/`Link` headers.
3. `/node/*`, `/files/download`, `/metrics`, `/health*`, the media-worker endpoints, MCP and the API-key endpoints are machine contracts: no change at all.
4. v1 and v2 differ on purpose: v1 `GET /api/v1/movies` is an API-key search with `actress`, ISO dates and cursor pagination and returns `community_score`, `completeness_tier`, boolean `censored` and `performers[].name_romaji`; v2 uses `released_from` as year, page/per_page and returns `score`, `quality_tier`, integer `censored`, `performers[].name`. Putting the v2 shapes on the v1 paths would break clients, which is why v2 is a new major version.

## MCP tools exposed by POST /api/v1/mcp (tools/list), must stay identical

- `search_scenes`
- `lookup_movies`
- `get_movie`
- `search`
- `lookup_performers`
- `get_counts`
- `crawl_status`
- `system_status`
- `get_performer`
- `get_catalog`
- `suggest`
- `get_related_movies`
- `get_release_calendar`

(13 tools found in McpToolService::definitions(); the replay test calls `tools/list` and compares names, descriptions and input schemas, then calls every tool with a recorded argument set.)

## Operations

| Method | Path | Auth | Controller / note | v2 equivalent |
|---|---|---|---|---|
| GET | /api/health | public | HealthController | — |
| GET | /api/health/live | public | HealthLiveController | — |
| GET | /api/openapi.json | public | OpenApiController, serves resources/openapi/javmeta.json (OpenAPI 3.0.3, 36 paths) | — |
| GET | /api/v1/auth/verify | API key (AuthenticateApiKey + LogApiUsage) |  | — |
| GET | /api/v1/config/browser-services | API key (AuthenticateApiKey + LogApiUsage) | BrowserServicesConfigController | — |
| GET | /api/v1/movies | API key (AuthenticateApiKey + LogApiUsage) | MovieSearchController; params q, code, genre, actress, maker, series, label, released_from/to (Y-m-d), runtime_*, censored, on_disk, actress_id, genre_all, source, score_min, tier_min, has_cover, has_magnet, has_performers, sort (relevance, release_date, update_date, rating), cursor; CacheApiResponse | GET /api/v2/movies (different params and shape) |
| GET | /api/v1/movies/calendar | API key (AuthenticateApiKey + LogApiUsage) | MovieCalendarController | GET /api/v2/calendar |
| GET | /api/v1/movies/facets | API key (AuthenticateApiKey + LogApiUsage) | MovieFacetsController::index; CacheApiResponse | GET /api/v2/movies/facets |
| GET | /api/v1/movies/facets/values | API key (AuthenticateApiKey + LogApiUsage) | MovieFacetsController::values | GET /api/v2/movies/facets/values |
| GET | /api/v1/movies/{code} | API key (AuthenticateApiKey + LogApiUsage) | MovieLookupController::show; CacheApiResponse | GET /api/v2/movies/{code} |
| GET | /api/v1/movies/{code}/related | API key (AuthenticateApiKey + LogApiUsage) | MovieRelatedController | GET /api/v2/movies/{code}/related |
| GET | /api/v1/movies/{code}/media | API key (AuthenticateApiKey + LogApiUsage) | MovieMediaController | GET /api/v2/movies/{code}/media |
| POST | /api/v1/movies:bulk | API key (AuthenticateApiKey + LogApiUsage) | MovieBulkController::store | POST /api/v2/movies/lookups |
| GET | /api/v1/performers | API key (AuthenticateApiKey + LogApiUsage) | PerformerController::index; CacheApiResponse | GET /api/v2/performers |
| GET | /api/v1/performers/facets | API key (AuthenticateApiKey + LogApiUsage) | PerformerFacetsController::index | GET /api/v2/performers/facets |
| GET | /api/v1/performers/facets/values | API key (AuthenticateApiKey + LogApiUsage) | PerformerFacetsController::values | GET /api/v2/performers/facets/values |
| GET | /api/v1/performers/{id} | API key (AuthenticateApiKey + LogApiUsage) | PerformerController::show | GET /api/v2/performers/{id} |
| GET | /api/v1/performers/{id}/photos | API key (AuthenticateApiKey + LogApiUsage) | PerformerPhotosController | GET /api/v2/performers/{id}/photos |
| GET | /api/v1/meta/genres | API key (AuthenticateApiKey + LogApiUsage) | MetaGenreController; CacheApiResponse | GET /api/v2/catalogs/genres |
| GET | /api/v1/meta/catalog/{kind} | API key (AuthenticateApiKey + LogApiUsage) | CatalogController (genres, makers, series, labels, years, cups, blood_types, locations) | GET /api/v2/catalogs/{kind} |
| GET | /api/v1/suggest | API key (AuthenticateApiKey + LogApiUsage) | SuggestController; CacheApiResponse | GET /api/v2/suggestions |
| GET | /api/v1/search | API key (AuthenticateApiKey + LogApiUsage) | SearchController | GET /api/v2/search |
| POST | /api/v1/search/parse | API key (AuthenticateApiKey + LogApiUsage) | SearchParseController | POST /api/v2/search/interpretations |
| GET | /api/v1/status/summary | API key (AuthenticateApiKey + LogApiUsage) | StatusController::summary; CacheApiResponse | GET /api/v2/status/summary (public) |
| GET | /api/v1/status/sources | API key (AuthenticateApiKey + LogApiUsage) | StatusController::sources | GET /api/v2/sources (admin) |
| GET | /api/v1/scenes/search | API key (AuthenticateApiKey + LogApiUsage) | SceneSearchController | — |
| GET | /api/v1/mcp | API key (AuthenticateApiKey + LogApiUsage) | McpHttpController (MCP over HTTP: initialize, tools/list, tools/call, event stream) | — |
| POST | /api/v1/mcp | API key (AuthenticateApiKey + LogApiUsage) | McpHttpController (JSON-RPC) | — |
| GET | /api/v1/media/config | API key (media worker) | MediaWorkerController::config | — |
| GET | /api/v1/media/jobs/next | API key (media worker) | MediaWorkerController::next | — |
| POST | /api/v1/media/jobs/{id}/complete | API key (media worker) | MediaWorkerController::complete | — |
| POST | /api/v1/media/jobs/{id}/fail | API key (media worker) | MediaWorkerController::fail | — |
| GET | /api/v1/admin/keys | owner/admin session guard (OwnerAdminGuard) | ApiKeyController::index | GET /api/v2/api-keys |
| POST | /api/v1/admin/keys | owner/admin session guard (OwnerAdminGuard) | ApiKeyController::store | POST /api/v2/api-keys |
| GET | /api/v1/admin/keys/{id}/usage | owner/admin session guard (OwnerAdminGuard) | ApiKeyController::usage | GET /api/v2/api-keys/{id}/usage |
| DELETE | /api/v1/admin/keys/{id} | owner/admin session guard (OwnerAdminGuard) | ApiKeyController::destroy | DELETE /api/v2/api-keys/{id} |
| GET | /api/v1/admin/report/{type} | owner/admin session guard (OwnerAdminGuard) | ReportController::show (quality, cost, sources) | GET /api/v2/reports/{type} |
| PUT | /api/v1/admin/sources/{slug}/login-cookies | owner/admin session guard (OwnerAdminGuard) | SourceLoginCookieController::update | — |
| DELETE | /api/v1/admin/sources/{slug}/login-cookies | owner/admin session guard (OwnerAdminGuard) | SourceLoginCookieController::destroy | — |
| GET | /metrics | public | PostgresConnectionMetricsController (Prometheus scrape) | — |
| GET | / | public | redirect to /app | — |
| GET | /files/download | signed + super administrator | FileController::download (signed file links, Range) | — |
| GET | /login | public | LoginController::show | — |
| POST | /login | public | LoginController::store (throttle 6/min) | — |
| POST | /logout | session | LoginController::destroy | — |
| GET | /app | owner or guest | UiShellController (legacy UI shell) | — |
| GET | /ui-api/{path} | owner or guest | UiApiController: in-process bridge to /api/v1/<path> (GET) | — |
| POST\|PUT\|PATCH\|DELETE | /ui-api/{path} | owner or guest | UiApiController write guard | — |
| POST | /ui-api/movies/{code}/downloads/search | guest or owner | UiMovieDownloadSearchController::store | — |
| GET | /node/join.sh | public | NodeEnrollmentController::script | — |
| POST | /node/enroll | public (throttle 6/min) | NodeEnrollmentController::redeem | — |
| GET | /ops | super administrator | Operations dashboard (Blade) | — |
| GET | /ops/data | super administrator | Operations dashboard JSON | — |
| GET | /ops/nodes | super administrator | NodeController::index | — |
| POST | /ops/nodes | super administrator | NodeController::store | — |
| GET | /ui-admin/keys | session (web guard) (owner/admin) | UiAdminController::keys | GET /api/v2/api-keys |
| POST | /ui-admin/keys | session (web guard) (owner/admin) | UiAdminController::createKey | POST /api/v2/api-keys |
| GET | /ui-admin/keys/{id}/usage | session (web guard) (owner/admin) | usage | GET /api/v2/api-keys/{id}/usage |
| DELETE | /ui-admin/keys/{id} | session (web guard) (owner/admin) | revoke | DELETE /api/v2/api-keys/{id} |
| GET | /ui-admin/report/periodic | session (web guard) (owner/admin) | UiReportController::show | GET /api/v2/reports/schedule |
| GET | /ui-admin/report/daily | session (web guard) (owner/admin) | showDaily | GET /api/v2/reports/schedule |
| POST | /ui-admin/report/periodic/send-now | session (web guard) (owner/admin) | sendNow | POST /api/v2/reports/deliveries |
| GET | /ui-admin/report/{type} | session (web guard) (owner/admin) | UiAdminController::report | GET /api/v2/reports/{type} |
| GET | /ui-admin/settings | session (web guard) (owner/admin) | UiSettingsController::index | GET /api/v2/settings |
| PUT | /ui-admin/settings | session (web guard) (owner/admin) | UiSettingsController::update | PUT /api/v2/settings/{group} |
| GET | /ui-admin/notifications | session (web guard) (owner/admin) | UiNotificationTemplateController::index | GET /api/v2/notification-templates |
| GET | /ui-admin/notifications/{key} | session (web guard) (owner/admin) | show | GET /api/v2/notification-templates/{key} |
| PUT | /ui-admin/notifications/{key} | session (web guard) (owner/admin) | update | PUT /api/v2/notification-templates/{key} |
| POST | /ui-admin/notifications/{key}/preview | session (web guard) (owner/admin) | preview | POST …/previews |
| POST | /ui-admin/notifications/{key}/test | session (web guard) (owner/admin) | testSend | POST …/test-deliveries |
| POST | /ui-admin/notifications/{key}/reset | session (web guard) (owner/admin) | reset | DELETE …/overrides |
| GET | /ui-admin/notifications/{key}/revisions | session (web guard) (owner/admin) | revisions | GET …/revisions |
| POST | /ui-admin/notifications/{key}/revisions/{revision}/restore | session (web guard) (owner/admin) | restore | POST …/revisions/{rev}/restorations |
| GET | /ui-me/marks | session (web guard) (owner/admin) | UiMarksController::index | GET /api/v2/me/marks |
| POST | /ui-me/marks | session (web guard) (owner/admin) | toggle (kinds incl. saved_search) | PUT\|DELETE /api/v2/me/marks/…, /me/saved-searches |
| GET | /ui-me/movies/{kind} | session (web guard) (owner/admin) | movies | GET /api/v2/me/marks/movies |
| GET | /ui-me/feed | session (web guard) (owner/admin) | feed | GET /api/v2/me/feed |
| POST | /ui-me/feed/seen | session (web guard) (owner/admin) | feedSeen | PATCH /api/v2/me/feed |
| GET | /ui-me/downloads/status | session (web guard) (owner/admin) | UiDownloadsController::status | GET /api/v2/download-status |
| GET | /ui-me/downloads | session (web guard) (owner/admin) | index | GET /api/v2/download-items |
| POST | /ui-me/downloads | session (web guard) (owner/admin) | store | POST /api/v2/download-items |
| POST | /ui-me/downloads/{hash}/pause | session (web guard) (owner/admin) | pause | PATCH /api/v2/download-items/{hash} |
| POST | /ui-me/downloads/{hash}/resume | session (web guard) (owner/admin) | resume | PATCH /api/v2/download-items/{hash} |
| DELETE | /ui-me/downloads/{hash} | session (web guard) (owner/admin) | destroy | DELETE /api/v2/download-items/{hash} |
| GET | /ui-me/downloads/{hash}/links | session (web guard) (owner/admin) | links | GET /api/v2/download-items/{hash}/links |
| GET | /ui-me/player/{code} | session (web guard) (owner/admin) | UiPlayerController::show | POST /api/v2/playback-sessions |
| GET | /ui-me/media | session (web guard) (owner/admin) | UiMediaController::index | GET /api/v2/media-jobs |
| GET | /ui-me/media/files | session (web guard) (owner/admin) | files | GET /api/v2/media-files |
| POST | /ui-me/media/capture | session (web guard) (owner/admin) | capture | POST /api/v2/media-jobs |
| POST | /ui-me/media | session (web guard) (owner/admin) | store | POST /api/v2/media-jobs |
| POST | /ui-me/media/{id}/retry | session (web guard) (owner/admin) | retry | PATCH /api/v2/media-jobs/{id} |
| GET | /ui-me/library | session (web guard) (owner/admin) | UiLibraryController::summary | GET /api/v2/library |
| GET | /ui-me/library/codes | session (web guard) (owner/admin) | codes | GET /api/v2/library/codes |
| GET | /ui-me/library/on-disk | session (web guard) (owner/admin) | onDisk | GET /api/v2/library/files |
| GET | /ui-me/library/unmatched | session (web guard) (owner/admin) | unmatched | GET /api/v2/library/files?match=unmatched |

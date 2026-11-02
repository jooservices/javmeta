# Guest mode route audit

Guest mode is enabled when `ui_security.login_required` is `false`. The route
matrix below records every route registered by Laravel and its installed
packages on the current branch. `GET, HEAD` is considered read-only; the UI
bridge applies a second path allow-list and rejects every write method for a
guest. The route matrix feature test compares this table with `Route::getRoutes()`
and with `GuestModeRoutePolicy::ALLOWED_ROUTES`, then sends anonymous requests
to each registered method.

The login form is public so an owner can sign in from the guest banner. Its
`POST` route accepts credentials only; an empty anonymous submission redirects
back to login. The form's CSRF nonce is an anti-forgery value, not an owner
session or API credential.

## Route matrix

| Methods | Route | Guest access | Reason |
| --- | --- | --- | --- |
| `GET, HEAD` | `/` | Guest allowed | Redirects to the UI shell without returning data. |
| `GET, HEAD` | `api/health` | Guest allowed | Guest mode returns only `status` and `checked_at`; dependency, worker, queue, and source details stay private. |
| `GET, HEAD` | `api/health/live` | Guest allowed | Returns only the fixed process liveness status. |
| `GET, HEAD` | `api/openapi.json` | Guest allowed | Static API contract; contains no runtime URLs, credentials, or server settings. |
| `GET, HEAD` | `api/v1/admin/keys` | Login required | Owner admin guard protects API key inventory. |
| `POST` | `api/v1/admin/keys` | Login required | Owner admin guard protects API key creation. |
| `DELETE` | `api/v1/admin/keys/{id}` | Login required | Owner admin guard protects API key revocation. |
| `GET, HEAD` | `api/v1/admin/keys/{id}/usage` | Login required | Owner admin guard protects per-key usage. |
| `GET, HEAD` | `api/v1/admin/report/{type}` | Login required | Owner admin guard protects operational reports. |
| `PUT` | `api/v1/admin/sources/{slug}/login-cookies` | Login required | Owner admin guard protects source login cookies. |
| `DELETE` | `api/v1/admin/sources/{slug}/login-cookies` | Login required | Owner admin guard protects source login cookies. |
| `GET, HEAD` | `api/v1/auth/verify` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/config/browser-services` | Login required | Requires a scoped API key; returns worker configuration. |
| `GET, HEAD, POST` | `api/v1/mcp` | Login required | Requires an MCP-scoped API key; tool calls are privileged actions. |
| `GET, HEAD` | `api/v1/media/config` | Login required | Requires a media API key; returns local worker settings. |
| `GET, HEAD` | `api/v1/media/jobs/next` | Login required | Requires a media API key and claims a job. |
| `POST` | `api/v1/media/jobs/{id}/complete` | Login required | Requires a media API key and completes a job. |
| `POST` | `api/v1/media/jobs/{id}/fail` | Login required | Requires a media API key and changes job state. |
| `GET, HEAD` | `api/v1/meta/catalog/{kind}` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/meta/genres` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/movies` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/movies/calendar` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/movies/facets` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/movies/{code}` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/movies/{code}/related` | Login required | Requires a scoped API key. |
| `POST` | `api/v1/movies:bulk` | Login required | Requires a scoped API key and writes catalog records. |
| `GET, HEAD` | `api/v1/performers` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/performers/facets` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/performers/{id}` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/scenes/search` | Login required | Requires a scoped API key; may return local scene and file references. |
| `GET, HEAD` | `api/v1/search` | Login required | Requires a scoped API key. |
| `POST` | `api/v1/search/parse` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `api/v1/status` | Guest allowed | Guest response keeps only status, message, and timestamp; version, environment, maintenance, and checks are removed. |
| `GET, HEAD` | `api/v1/status/sources` | Login required | Requires a scoped API key; reports crawler source operations. |
| `GET, HEAD` | `api/v1/status/summary` | Login required | Requires a scoped API key; reports crawl and worker operations. |
| `GET, HEAD` | `api/v1/suggest` | Login required | Requires a scoped API key. |
| `GET, HEAD` | `app` | Guest allowed | Static UI shell; guest response omits session/CSRF and command-center metadata and is `noindex`. |
| `GET, HEAD` | `files/download` | Login required | Requires an owner session, super-administrator role, and signed URL. |
| `GET, HEAD` | `horizon/api/batches` | Login required | Horizon authorization gate protects batch data. |
| `POST` | `horizon/api/batches/retry/{id}` | Login required | Horizon authorization gate protects retry actions. |
| `GET, HEAD` | `horizon/api/batches/{id}` | Login required | Horizon authorization gate protects batch data. |
| `GET, HEAD` | `horizon/api/jobs/completed` | Login required | Horizon authorization gate protects job data. |
| `GET, HEAD` | `horizon/api/jobs/failed` | Login required | Horizon authorization gate protects job data. |
| `GET, HEAD` | `horizon/api/jobs/failed/{id}` | Login required | Horizon authorization gate protects job details. |
| `GET, HEAD` | `horizon/api/jobs/pending` | Login required | Horizon authorization gate protects job data. |
| `POST` | `horizon/api/jobs/retry/{id}` | Login required | Horizon authorization gate protects retry actions. |
| `GET, HEAD` | `horizon/api/jobs/silenced` | Login required | Horizon authorization gate protects job data. |
| `GET, HEAD` | `horizon/api/jobs/{id}` | Login required | Horizon authorization gate protects job details. |
| `GET, HEAD` | `horizon/api/masters` | Login required | Horizon authorization gate protects supervisor data. |
| `GET, HEAD` | `horizon/api/metrics/jobs` | Login required | Horizon authorization gate protects job metrics. |
| `GET, HEAD` | `horizon/api/metrics/jobs/{id}` | Login required | Horizon authorization gate protects job metrics. |
| `GET, HEAD` | `horizon/api/metrics/queues` | Login required | Horizon authorization gate protects queue metrics. |
| `GET, HEAD` | `horizon/api/metrics/queues/{id}` | Login required | Horizon authorization gate protects queue metrics. |
| `GET, HEAD` | `horizon/api/monitoring` | Login required | Horizon authorization gate protects monitored jobs. |
| `POST` | `horizon/api/monitoring` | Login required | Horizon authorization gate protects monitoring changes. |
| `GET, HEAD` | `horizon/api/monitoring/{tag}` | Login required | Horizon authorization gate protects monitored jobs. |
| `DELETE` | `horizon/api/monitoring/{tag}` | Login required | Horizon authorization gate protects monitoring changes. |
| `GET, HEAD` | `horizon/api/stats` | Login required | Horizon authorization gate protects operational statistics. |
| `GET, HEAD` | `horizon/api/workload` | Login required | Horizon authorization gate protects worker workload. |
| `GET, HEAD` | `horizon/{view?}` | Login required | Horizon authorization gate protects the dashboard. |
| `GET, HEAD` | `login` | Guest allowed | Public authentication form; it exposes no owner or API credentials. |
| `POST` | `login` | Login required | Credential exchange only; invalid anonymous input redirects to the login form. |
| `POST` | `logout` | Login required | Requires an owner session. |
| `GET, HEAD` | `ops` | Login required | Requires an authenticated super-administrator. |
| `GET, HEAD` | `ops/data` | Login required | Requires an authenticated super-administrator; returns operational data. |
| `GET, HEAD` | `storage/{path}` | Login required | Private local files require a signed URL. |
| `PUT` | `storage/{path}` | Login required | Private local file uploads require a signed upload URL. |
| `GET, HEAD` | `ui-admin/keys` | Login required | Requires an authenticated super-administrator. |
| `POST` | `ui-admin/keys` | Login required | Requires an authenticated super-administrator and creates an API key. |
| `DELETE` | `ui-admin/keys/{id}` | Login required | Requires an authenticated super-administrator and revokes an API key. |
| `GET, HEAD` | `ui-admin/keys/{id}/usage` | Login required | Requires an authenticated super-administrator. |
| `GET, HEAD` | `ui-admin/report/{type}` | Login required | Requires an authenticated super-administrator; returns reports. |
| `GET, HEAD` | `ui-admin/settings` | Login required | Requires an authenticated super-administrator; may return settings values. |
| `PUT` | `ui-admin/settings` | Login required | Requires an authenticated super-administrator and changes settings. |
| `GET, HEAD` | `ui-api/{path}` | Guest allowed | Read-only bridge with a strict public catalog allow-list, field redaction, per-IP rate limiting, and `noindex` headers. |
| `DELETE, PATCH, POST, PUT` | `ui-api/{path}` | Login required | Guest mode rejects writes; signed-in owners may use only authorized operations. |
| `GET, HEAD` | `ui-me/downloads` | Login required | Requires an authenticated super-administrator; returns owner download state. |
| `POST` | `ui-me/downloads` | Login required | Requires an authenticated super-administrator and starts a download. |
| `GET, HEAD` | `ui-me/downloads/status` | Login required | Requires an authenticated super-administrator; returns download status. |
| `DELETE` | `ui-me/downloads/{hash}` | Login required | Requires an authenticated super-administrator and removes a download. |
| `GET, HEAD` | `ui-me/downloads/{hash}/links` | Login required | Requires an authenticated super-administrator; may return download links. |
| `POST` | `ui-me/downloads/{hash}/pause` | Login required | Requires an authenticated super-administrator and changes download state. |
| `POST` | `ui-me/downloads/{hash}/resume` | Login required | Requires an authenticated super-administrator and changes download state. |
| `GET, HEAD` | `ui-me/feed` | Login required | Requires an authenticated super-administrator; returns owner feed state. |
| `POST` | `ui-me/feed/seen` | Login required | Requires an authenticated super-administrator and changes owner feed state. |
| `GET, HEAD` | `ui-me/library` | Login required | Requires an authenticated super-administrator; returns the owner's library. |
| `GET, HEAD` | `ui-me/library/codes` | Login required | Requires an authenticated super-administrator; returns owner library codes. |
| `GET, HEAD` | `ui-me/library/on-disk` | Login required | Requires an authenticated super-administrator; returns local file state. |
| `GET, HEAD` | `ui-me/library/unmatched` | Login required | Requires an authenticated super-administrator; returns owner library matches. |
| `GET, HEAD` | `ui-me/marks` | Login required | Requires an authenticated super-administrator; returns owner favorites and watch state. |
| `POST` | `ui-me/marks` | Login required | Requires an authenticated super-administrator and changes owner marks. |
| `GET, HEAD` | `ui-me/media` | Login required | Requires an authenticated super-administrator; returns local media jobs. |
| `POST` | `ui-me/media` | Login required | Requires an authenticated super-administrator and queues media work. |
| `POST` | `ui-me/media/capture` | Login required | Requires an authenticated super-administrator and starts media capture. |
| `GET, HEAD` | `ui-me/media/files` | Login required | Requires an authenticated super-administrator; returns local media files. |
| `POST` | `ui-me/media/{id}/retry` | Login required | Requires an authenticated super-administrator and retries media work. |
| `GET, HEAD` | `ui-me/movies/{kind}` | Login required | Requires an authenticated super-administrator; returns owner favorites or watch state. |
| `GET, HEAD` | `ui-me/player/{code}` | Login required | Requires an authenticated super-administrator; may return a signed local media link. |
| `GET, HEAD` | `up` | Guest allowed | Laravel liveness response exposes only process health. |

## Audit results

- Only the read-only UI shell, public catalog bridge, basic liveness pages, API
  schema, package liveness endpoint, root redirect, and login form are guest
  allowed. Guest catalog routes expose public catalog data only; owner marks,
  watch state, library, downloads, settings, API keys, jobs, and media remain
  unavailable.
- Guest health responses omit dependency endpoints, source details, worker
  names, queue details, and environment values. Guest bridge responses remove
  credentials, signed/download URLs, paths, internal URLs/IPs, and stack trace
  fields. The package liveness route also removes environment/version details.
  Server errors on the guest bridge return a generic response.
- The shell and every guest bridge response, including denials and rate limits,
  carries `X-Robots-Tag: noindex, nofollow` and `Cache-Control: no-store`.
  The bridge uses `ui_guest.rate_limit_per_minute` per client IP.
- Horizon is registered and protected by its authorization gate. No Telescope
  routes or HTTP webhook routes are registered. Telegram polling is a scheduled
  command, not an inbound webhook route.
- The private `storage` GET and PUT routes are framework-generated and require
  signed URLs; `/files/download` also requires owner authentication and a
  super-administrator role.

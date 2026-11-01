# XCrawler UI

[User guide (Tiếng Việt)](../user-guide/README.md) · [Developer guide](../developer-guide/README.md) · [Testing](../developer-guide/testing.md) · [Verified documentation differences](../developer-guide/verified-differences.md)

## Running application

The UI is plain ES modules under `public/ui`, served at `/app` with static files at `/ui/*`. The Cài đặt page's `ui_security.login_required` database setting defaults to `true` and protects the shell and read bridge with the owner login. When set to `false`, cookie-free requests bypass session middleware and receive a read-only guest view of the public catalogue and safe crawl status. A stale unauthenticated session cookie is not renewed. Personal, admin, media, file and write features continue to require an owner login. Guest responses carry noindex headers and the GET bridge applies a per-IP limit from `ui_guest.rate_limit_per_minute` in database settings. Hash routes preserve filters and browser history. The bridge dispatches to `/api/v1/*` in-process; writes use `send()` with CSRF. Browsers never hold an API key.

Guests see a read-only banner, public navigation, and sign-in prompts for saved searches and owner-only pages. Personal marks, on-disk status, download controls, library details, signed media links, settings, admin screens, and the operations dashboard stay private. The `/api/v1/*` API-key contract is unchanged.

The current NAV exposes Discover, Tìm kiếm, Thống kê; Phim, Diễn viên, Hồ sơ diễn viên, Mới cập nhật, Thể loại, Maker, Series, Label, Năm, Lịch phát hành; Yêu thích, Xem sau, Đã xem, Theo dõi, Thư viện, Tải về; Crawl & nguồn, Vận hành (Command Center), Quản trị and Cài đặt. [The user index](../user-guide/README.md) links each topic and every settings group. Downloads and file serving default off, but the download page exists and explains the disabled state.

The omnibox suggests movies, performers, makers and series, plus a full-search link. It does not keep recent searches. Search results separately include genre suggestions. Ctrl/Cmd-K or `/` focuses search; `t` toggles theme, `?` opens help, Esc closes it, and arrows/Enter choose omnibox suggestions. The `g …` shortcuts listed by the Settings view are not implemented. The top status pill refreshes every 30 seconds; the crawl table itself requires a reload.

## Historical design reference

`concept-a.html` is the approved Concept A attachment from JOOXCR-197, with synthetic records and numbers. It is a visual reference, not a feature contract. Its proposed scenes, AI features and other mock interactions are not proof of implemented behavior. The running UI has dark/light/system themes and normal/compact density; use the existing responsive filter drawer on phones.

Render checks run through the shared Node runtime; see [tested commands](../developer-guide/testing.md).

## Search and filters

Movies and performers share URL-backed filters. Applying a filter creates a hash history entry; back/forward restores it.
For example, `#/movies?actress_id=1%2C2&genre=Drama%2CSchool&genre_all=1` finds co-stars with both genres.
Several performer IDs always mean **movies they share**, while several genres mean any unless “Tất cả thể loại” is selected.
The existing filter drawer is used on phones (including 375 px); grid/table, image size and page size are local preferences.
Both browsers load subsequent pages on scroll (movie cursors, performer pages).

### Movies

| Control | URL parameter | Meaning |
| --- | --- | --- |
| Từ khoá | `q` | Title, code or performer text; use at least two characters |
| Mã phim | `code` | Movie code |
| Diễn viên | `actress_id` | Comma-separated performer IDs; selected chips require all performers |
| Tên chứa… | `actress` | Partial romaji, kana, kanji or alias |
| Thể loại | `genre` | Comma-separated genre names; searchable checkboxes with current-result counts |
| Any/all genres | `genre_all` | `1` requires every selected genre; `0` or absent allows any |
| Hãng / Series / Nhãn | `maker`, `series`, `label` | Text fields with catalog suggestions and current-result top values |
| Từ năm / Đến năm | `released_from`, `released_to` | Inclusive year bounds; sent to the API as January 1 / December 31 |
| Runtime | `runtime_min`, `runtime_max` | Inclusive minutes |
| Điểm tối thiểu | `score_min` | Slider from 0 to 10 |
| Độ đầy đủ tối thiểu | `tier_min` | Completeness tier 0–3 |
| Kiểm duyệt | `censored` | Any, `censored` (yes), `uncensored` (no) |
| Trên máy | `on_disk` | Any, `1` (yes), `0` (no) |
| Có ảnh bìa / magnet / diễn viên | `has_cover`, `has_magnet`, `has_performers` | Independent any/yes/no selections |
| Nguồn | `source` | Comma-separated source slugs, any selected source |
| Xếp theo | `sort` | `relevance`, `release_date`, `update_date`, `rating` |

Legacy links using `year`, `from`, `to`, `rmin`, `rmax` are still accepted and normalized to current parameter names.
The performer combobox requests `suggest` after 250 ms and at least two characters. Use arrows, Enter to select and Escape
to close. Selecting returns focus to the input; ID names are resolved from `performers/{id}` and cached.

### Performers

| Control | URL parameter | Meaning |
| --- | --- | --- |
| Tên | `q` | Partial name or alias; at least two characters |
| Cup / Nhóm máu | `cup`, `blood_type` | Comma-separated values, any selected value; current-result counts |
| Nơi sống | `location` | Location text with catalog suggestions and facet values |
| Age | `age_min`, `age_max` | Inclusive age bounds |
| Height | `height_min`, `height_max` | Inclusive centimetres |
| Số đo (collapsed initially) | `bust_min`, `bust_max`, `waist_min`, `waist_max`, `hip_min`, `hip_max` | Independent inclusive body measurement ranges |
| Ra mắt từ / đến năm | `debut_from`, `debut_to` | Inclusive integer years (the performer API expects years) |
| Hoạt động | `status` | Any, `active`, `retired` |
| Có ảnh / tiểu sử | `has_image`, `has_bio` | Independent any/yes/no selections |
| Số phim tối thiểu | `movies_min` | Minimum linked movie count |
| Nguồn | `source` | Source slugs |
| Xếp theo | `sort` | `name`, `updated_at`, `linked_title_count` |

### Facets, empty results and saved searches

“Thu hẹp kết quả” counts values inside the current result. Click a value to toggle it; movie year values toggle the year
bounds. Active chips remove one condition or one selected value; “Xoá tất cả” removes all editable filters. Fixed filters on
facet and filmography pages remain in effect. Facet requests are aborted on navigation; previous values stay dimmed under a
loading skeleton. Failed requests offer retry. Catalog suggestions are cached for two minutes.

With zero results, the UI tests removal of up to three active conditions, then offers successful relaxations ordered by
result count. The `+N` count is the count after that one removal; these suggestions keep fixed page restrictions.

“Lưu bộ lọc” opens an inline name form. “Bộ lọc đã lưu” applies a saved query through the URL; its × button deletes it.
Saved searches are per owner and list kind (movies or performers) in `user_marks`, through authenticated `/ui-me/marks` with CSRF for changes.
Saving the same name replaces the query for that list kind, while another owner or the other list kind keeps its own entry.
Names allow 1–80 characters; queries allow up to 1500; there are no browser-stored secrets or new environment settings.

Verification: `/home/joos/Workspaces/scripts/docker/runtime node node ui-tests/render-smoke.mjs` includes sample facets/suggestions and parameter,
escaping, picker keyboard/debounce, request cancellation, cache and saved-search interaction assertions without site login.

The crawl source-health status for JavPhotos uses its configured gallery sample.
An upstream fetch failure remains visible as BLOCKED; defining the sample only
removes the false “no page to check” DEGRADED verdict.

# Performer list performance

## Performer list performance

Name and update-time lists batch movie counts for the returned page. Ordering, cursor fields and API response fields are unchanged. See [performance measurements](../03-operations/performance.md) for measured limits and pending PostgreSQL index verification.

## Performance cache setting

Cài đặt → Tìm kiếm includes **Làm ấm bộ nhớ đệm** (`perf.warm_enabled`). It controls background refresh of status and whole-catalog movie facets. Saving uses the existing authenticated settings flow. API fields and UI loading/error/empty states are unchanged. See [performance](../03-operations/performance.md) before interpreting benchmark numbers.

## Search performance setting

Cài đặt → Tìm kiếm exposes **Ngân sách tìm kiếm (mili giây)** (`search.budget_ms`). A smaller budget prioritizes keyword results when local models or vector services are slow. Full keyword performer pages skip performer vector lookup. The search response fields, authentication and UI states are unchanged. See [performance](../03-operations/performance.md) for measured limitations.

The Library page offers per-file preview and scene extraction buttons. **Media** (`#/media`) lists queued jobs, indeterminate progress while running, errors and retries for failed jobs. Paging and a refresh button keep the queue bounded. All changes use the owner's session and CSRF token; worker credentials are never sent to the browser.

Per-file Media actions include **Phụ đề** (local GPU Whisper) and **Dịch EN / VI** (local Ollama, after recognition). Missing prerequisites appear as job errors in the Media queue.

**Mô tả cảnh** runs local vision after scene extraction. The search page shows **Cảnh** in both keyword and meaning modes, with thumbnail (when serving is enabled), movie, timestamp and a player deep link. Scene failures do not hide movie/performer results.

**Phát** on an on-disk movie opens `#/player/:code`. It has native video controls, a file selector, original/EN/VI subtitle selection, scene seek buttons, explicit loading/playback errors and a layout that wraps at 375 px. A search scene deep link selects the file and start time. Reload renews signed links.

The Media page has **Ghi luồng** with a URL field. It remains disabled until capture is enabled in Settings. Each submit creates one explicit owner job; DRM/encryption refusals appear in the queue. Finished captures expose a signed **Mở tệp đã ghi** link when file serving is enabled. Capture is never offered as an automatic or bulk library action.

Capture refuses internal-network and cloud-metadata destinations, including redirects and segment hosts. Library paths and scene descriptions containing dollar signs render literally. A rejected worker result appears through the normal failure/retry flow instead of occupying a running slot until its lease expires.

Library scans skip file and directory symlinks, including links outside the configured root and directory loops. An unreadable subtree reports an error and prevents missing-file marking for that root during the incomplete scan. Settings → **Dữ liệu mẫu** exposes the database allow-list; dataset commands require `--force` outside testing and only modify explicitly owned sample rows.

Settings warns when a legacy service base URL is invalid and uses the config default until the owner saves a valid HTTP(S) address without credentials, query or fragment. Telegram group commands and download buttons additionally require the configured **User ID chủ sở hữu Telegram**; private chats keep their chat-ID authorization. See [settings operations](../03-operations/settings.md).

Cài đặt → **Sức khỏe khi khởi động** controls primary-role policy, retry timing, web snapshot TTL and Artisan command exceptions. PostgreSQL, Redis and MongoDB remain primary; Elasticsearch defaults to primary on web and secondary on workers. A red primary health snapshot returns 503 before the owner login flow; `/api/health` and `/api/health/live` remain available for diagnosis. See [startup health operations](../03-operations/startup-health.md).

## Fetch diagnostics retention

Cài đặt / Giám sát exposes **Số ngày giữ lịch sử thử tải** (default 14).
The per-method report is an operator command, not a new UI screen; see
[fetch attempts](../03-operations/telemetry.md#fetch-attempts).

Native XCrawler production has independent host/index settings. See the
[production operator runbook](../../deploy/production/README.md)
for the Settings-page checklist; restarting Horizon on that node uses `xcrawler-horizon`.

Installation operators use the [install/deploy wizard](../03-operations/install-wizard.md).
Initial host and service answers initialize missing database settings; later
changes made in **Settings** remain authoritative across deployments.

The Crawl & sources table shows each source’s effective lane (Trực tiếp or Trình duyệt). Configure lane thresholds, pins and the split switch in Cài đặt → Hàng đợi crawl.
The same group exposes **Feed interval (seconds)** (`queue.feed_interval_seconds`), with choices 5, 10, 15, 30 and 60. The default is 10 seconds; 60 restores the former once-per-minute cadence. Changes apply on the next scheduler minute.

Product-code recovery for javbtc, ffjav, tokyohot and 141jav preserves source
product identifiers and uses a stable source-scoped URL identity when needed.
The movie remains accessible through the existing movie UI; no new controls
or settings are added. Long source codes wrap in the movie detail, including
the breadcrumb and metadata, without truncating copy or navigation identities.
Operator dry-run and requeue instructions are in
[queue operations](../03-operations/queue.md#product-code-recovery-jooxcr-283).

Performer identity co-credit merges retain all source profiles on the canonical
performer. Operational dry runs, review resolution and reversal are documented
in [performer identity](../03-operations/performer-identity.md#co-credit-identity).

## Legacy telemetry maintenance

Legacy OpenObserve stream removal is an operator-only Artisan command, with no browser action. Follow [Removing legacy streams](../03-operations/observability.md#removing-legacy-streams) for dry-run review, complete exports, confirmation and live-stream verification.

# Bug hunt: front-end (public/ui plain ES modules) and browser behaviour (JOOXCR-414)

Scope: `public/ui/**` (index.html, js/*.js, js/views/*.js, kit.css) and `ui-tests/**`.
Branch `JOOXCR-bughunt-frontend` (from `JOOXCR-373-readiness`, commit `1b667d9`). Report only: no application code or tests were changed.

## Counts by severity

| Severity | Count | IDs |
|---|---|---|
| S1 (data loss / security / outage) | 0 | — |
| S2 (wrong results / visible defect) | 7 | 001–007 |
| S3 (minor / robustness) | 16 | 008–023 |

Status: 7 S2 findings have browser or DOM evidence (see each entry). The S3 items marked "by reading" have code evidence only.

## Fix first (ranked)

1. **BH-frontend-005** — a malformed `%` escape in the hash blanks the whole app (no shell, no error box). One bad link breaks the page.
2. **BH-frontend-001** — every visit to Notifications / Report / Settings / Admin adds another click handler to the persistent `#view`; one click then runs N times (N saves, N Telegram sends, N deletes).
3. **BH-frontend-002** — a single failed `/ui-me/marks` call makes every favourite/watch-later/seen/follow button show "off" for the whole session.
4. **BH-frontend-003** — the "Liên kết" (download links) panel is wiped within about 4 s by the 5-second list re-render.
5. **BH-frontend-004** — facet routes decode their value twice; `%` in a maker/series/genre name breaks the page, `%20` in a name searches for a different value.
6. **BH-frontend-006** — a 502/503/504 HTML body is reported as "Phiên đăng nhập đã hết hạn" (session expired), which sends the user to the wrong fix.
7. **BH-frontend-007** — API URLs (`source_url`, gallery, download links) go into `href` with no scheme check; `javascript:` renders as a live link (DOM confirmed; backend reachability suspected).
8. **BH-frontend-008** — the home page's "released today" uses the UTC date, so for the owner (UTC+7) it is wrong from 00:00 to 07:00 local time.
9. **BH-frontend-009** — performer initials are not escaped; a name starting with `<` breaks the card's markup.
10. **BH-frontend-011** — the Crawl page says "cập nhật mỗi 30 giây" but nothing re-renders its table.

## Findings

Summary table (details and reproduction in the sections below):

| id | sev | file:line | summary | status |
|---|---|---|---|---|
| BH-frontend-001 | S2 | `views/notifications.js:130,197,204,209,223`; `views/report.js:173,185`; `views/settings.js:46,83,84`; `views/admin.js:58` | Listeners are added to the persistent `#view` on every render and never removed; one click runs N handlers | CONFIRMED (GET count); POST duplication follows from the same mechanism |
| BH-frontend-002 | S2 | `js/marks.js:45-50` | A failed marks fetch is cached as an empty set; favourites show "off" until reload | CONFIRMED |
| BH-frontend-003 | S2 | `views/downloads.js:33,100` | 5 s `innerHTML` refresh wipes the "Liên kết" output and drops keyboard focus | CONFIRMED |
| BH-frontend-004 | S2 | `js/app.js:149` + `views/facet.js:265` | Facet value decoded twice: `50%` → "URI malformed"; `a%2520b` searches `a b` | CONFIRMED |
| BH-frontend-005 | S2 | `js/app.js:141-149,169-171` | `decodeURIComponent` throws outside the render `try`; the shell is never drawn | CONFIRMED |
| BH-frontend-006 | S2 | `js/api.js:25-27,57` | Any non-JSON response (proxy 502 HTML) is reported as "session expired" | CONFIRMED |
| BH-frontend-007 | S2 | `views/movie.js:50,54`; `views/performer.js:121`; `views/downloads.js:111` | Unvalidated URLs go into `href`; `javascript:` is rendered as a link | DOM CONFIRMED; reachability SUSPECTED |
| BH-frontend-008 | S3 | `views/home.js:227` | "Today" computed from `toISOString()` (UTC), not ICT | CONFIRMED |
| BH-frontend-009 | S3 | `js/components.js:36`; `js/util.js:308` | `initials()` output not escaped; `<b x` breaks the avatar markup | CONFIRMED |
| BH-frontend-010 | S3 | `js/util.js:328-330`; `views/admin.js:51` | When the clipboard API fails, `copyText` shows the text in a toast (API key, signed links) | CONFIRMED by reading |
| BH-frontend-011 | S3 | `views/crawl.js:123,139`; `js/app.js:300-301` | "cập nhật mỗi 30 giây" is false: the table never re-renders | CONFIRMED by reading |
| BH-frontend-012 | S3 | `js/marks.js:48`; `js/marks.js:62`; `app/Services/Ui/UiMarksService.php:19-27`; `app/Repositories/LibraryFileRepository.php:52-60` | Whole-collection fetches: all owner marks and every on-disk code, not paginated | SUSPECTED (size unknown) |
| BH-frontend-013 | S3 | `views/player.js:136,137` | Route `code` put into `head()` unescaped (latent; 404 path avoids it) | SUSPECTED (latent) |
| BH-frontend-014 | S3 | `views/crawl.js:136` | `last_error.slice(0, 70)` splits UTF-16 surrogate pairs (emoji) | CONFIRMED |
| BH-frontend-015 | S3 | `js/app.js:276` | `t` shortcut ignores modifiers: Ctrl+T toggles the theme and opens a tab | CONFIRMED by reading |
| BH-frontend-016 | S3 | `js/facets.js:189-201` | Stale request's `finally` hides the loading state of the newer request | SUSPECTED |
| BH-frontend-017 | S3 | `views/notifications.js:52-63` | Preview responses are not sequenced; an older response can overwrite a newer one | SUSPECTED |
| BH-frontend-018 | S3 | `views/catalog.js:48-50,253` | Debounced `go()` is not cancelled on leave; typing then navigating away can bounce back | SUSPECTED |
| BH-frontend-019 | S3 | `views/settings.js:106` | `location.reload()` 1.5 s after save reloads whatever page the user is on | SUSPECTED |
| BH-frontend-020 | S3 | `views/settings.js:26,58-70` | Setting inputs have no associated `<label>` (unnamed form controls) | CONFIRMED by markup |
| BH-frontend-021 | S3 | `views/report.js:192-195` | Numeric selects send strings; empty numeric input saves `0` | SUSPECTED |
| BH-frontend-022 | S3 | `js/api.js:7,33` | `cache` Map keyed by URL is never evicted (TTL checked only on read) | CONFIRMED by reading |
| BH-frontend-023 | S3 | `views/movie.js:19,25-56` | Unknown `?tab=` shows an empty panel | CONFIRMED by reading |

---

### BH-frontend-001 — S2 — stacked listeners on the persistent `#view`

- **Where:** `public/ui/js/app.js:186-189` (`out.mount(view, r)`, `view` is always `$('#view')`), `views/notifications.js:130,197,204,209,223`, `views/report.js:173,185`, `views/settings.js:46,83,84`, `views/admin.js:58`. The `mount` functions return no cleanup, so `cleanup` stays null.
- **Summary:** `#view` is the same DOM node for every route. Each visit to a page that binds on `root` adds listeners that are never removed. A click then runs once per visit.
- **Reproduction (executed):** in-app navigation (`location.hash = ...`, not `page.goto`, which reloads):
  1. Open `#/settings/notifications`, then go to `#/home` and back to `#/settings/notifications` three times.
  2. Count `GET /ui-admin/notifications/<key>` requests after one click on the first "Sửa" button.
  - Result: **4** (expected 1).
  3. Same procedure on `#/settings/report`, then click "Làm mới": **3** `GET /ui-admin/report/periodic` (expected 1).
- **Evidence:** the run was against the QA stack (port 18099) with the owner session. The same mechanism applies to the POST paths: "Gửi ngay qua Telegram" (`report.js:206-211`, one `askConfirm` plus one send per listener), "Gửi thử" and "Khôi phục" (`notifications.js`), "Lưu" (`settings.js:84-110`), "Thu hồi khoá" (`admin.js:58-60`, a `DELETE` per listener). These POST counts were not executed.
- **Root cause (confirmed):** no per-mount AbortController or cleanup; `#view` persists across routes.
- **Proposed fix:** have each `mount` return a cleanup that removes its listeners, or bind through a per-render `AbortController` (`{ signal }`) that `render()` aborts before the next route. Apply the same to `settings.js` `mountSystem`.
- **Proposed failing tests:** `notifications: one edit request per click after three revisits` (Playwright, in-app navigation); `report: one refresh request per click after three revisits`; `admin: one DELETE per revoke click after two revisits`.

### BH-frontend-002 — S2 — failed marks fetch is cached as empty

- **Where:** `public/ui/js/marks.js:45-50`:
  ```js
  if (!cache || force) {
    try { cache = new Set(...); } catch { cache = new Set(); }
  }
  ```
- **Summary:** after a failed fetch, `cache` becomes an empty Set, which is truthy, so no later call ever retries until a forced refresh (a successful toggle). Every mark button then shows "off" for the whole session.
- **Reproduction (executed):** route the first `/ui-me/marks` call to HTTP 500 and every later call to `[{kind:'favourite', subject:'movie', key:'TST-002'}]`; visit `#/movie/TST-001`, then in-app to `#/movie/TST-002`.
  - Result: `marks_calls = 1`; `button[data-kind=favourite]` has `aria-pressed="false"` on TST-002 although the server reports it as a favourite.
  - Control: a full page reload on TST-002 returns `aria-pressed="true"`.
- **Root cause (confirmed):** the error path stores the failure as valid state.
- **Proposed fix:** cache only on success (keep `null` on failure so the next `myMarks()` retries), and show a non-blocking "Không tải được dấu của bạn" hint on the page.
- **Proposed failing test:** `marks: a failed fetch is retried on the next render`.

### BH-frontend-003 — S2 — download links panel wiped by the 5 s refresh

- **Where:** `public/ui/js/views/downloads.js:33` (`setInterval(refresh, 5000)`), `:100` (`list.innerHTML = ...`), `:111` (links written into `[data-out]`, which the next refresh destroys).
- **Summary:** the whole list is re-rendered every 5 seconds. The links that a user just requested are removed in under 5 seconds, and any focused button loses focus.
- **Reproduction (executed):** mock `/ui-me/downloads/status` (enabled), `/ui-me/downloads` (one completed item), `/ui-me/downloads/abc123/links`; open `#/downloads`; click "Liên kết"; sample `[data-out] a.lnk` once per second for 8 s.
  - Result: `[1, 1, 1, 1, 0, 0, 0, 0]`: the links disappear after about 4 s.
- **Root cause (confirmed):** the refresh replaces the DOM the user is interacting with.
- **Proposed fix:** update rows in place, keyed by `data-hash`, and keep the `[data-out]` content in a Map keyed by hash that survives the refresh. Do not refresh while a row has an open panel.
- **Proposed failing test:** `downloads: the links panel survives the 5 s refresh`.

### BH-frontend-004 — S2 — facet value decoded twice

- **Where:** `public/ui/js/app.js:149` (`params[n] = decodeURIComponent(m[i + 1])`) and `public/ui/js/views/facet.js:265` (`decodeURIComponent(params.value)`).
- **Summary:** the router already decodes the segment. `facet.js` decodes it again, so any literal `%` in the value fails or changes meaning.
- **Reproduction (executed):**
  - `#/facet/maker/50%25` → the view shows "Không tải được dữ liệu / URI malformed / Thử lại" (`URIError` from the second decode of `50%`).
  - `#/facet/maker/a%2520b` → `GET /ui-api/movies?maker=a b`: the page searches for `a b`, not `a%20b`.
- **Root cause (confirmed):** duplicate decode. `href.facet()` encodes once (`components.js:12`), which is correct on its own.
- **Proposed fix:** remove the decode in `facet.js`; make `parse()` decode each segment inside a `try` (see BH-frontend-005).
- **Proposed failing test:** `facet: a value containing % or %20 reaches the API unchanged`.

### BH-frontend-005 — S2 — malformed escape in the hash blanks the app

- **Where:** `public/ui/js/app.js:141-154` (`parse`, `decodeURIComponent` at line 149), `:169-171` (`render` calls `parse()` before `drawShell` and before its `try` at line 183).
- **Summary:** `decodeURIComponent` throws `URIError` on a stray `%`. `render()` has no `try` around `parse()`, so the shell and the view are never drawn. On first load the page is blank; on in-app navigation the old view remains and its cleanup has already run.
- **Reproduction (executed):** `goto('/#/movie/%E0%A4%A')`.
  - Result: `pageerror: "URI malformed"`; `#view` text length 0; `#side .nav` count 0 (no sidebar).
- **Root cause (confirmed).** Also affects any link with a hand-typed `%` in the path, and `#/player/%`, `#/performer/%`.
- **Proposed fix:** a `safeDecode` that returns the raw segment on failure; move `parse()` inside the try and render the not-found view on error.
- **Proposed failing test:** `router: a malformed percent-escape renders the not-found view with the shell`.

### BH-frontend-006 — S2 — non-JSON error reported as session expiry

- **Where:** `public/ui/js/api.js:25-27` (`api`) and `:57` (`admin`).
- **Summary:** the check `!type.includes('json')` treats any non-JSON response as an expired session. A proxy 502/503/504 HTML page, or a 403 HTML page, gets the message "Phiên đăng nhập đã hết hạn."
- **Reproduction (executed):** route `/ui-api/movies` to status 502, `text/html`; open `#/movies`.
  - Result, in the footer: "Phiên đăng nhập đã hết hạn. Thử lại".
- **Root cause (confirmed):** the message is chosen from the content type, not from the status.
- **Proposed fix:** only 401 or a redirect to `/login` gives the session message; other statuses say "Máy chủ lỗi (HTTP 502). Thử lại sau ít giây." and keep `status`.
- **Proposed failing test:** `api: a 502 HTML body is not reported as session expiry`.

### BH-frontend-007 — S2 (SUSPECTED reachability) — unvalidated URL schemes in `href`

- **Where:** `views/movie.js:50` (gallery `<a href="${esc(u)}" target="_blank">`), `:54` (`s.source_url`), `views/performer.js:121` (`s.source_url`), `views/downloads.js:111` (`l.url`). `esc()` escapes HTML, not schemes.
- **Summary:** nothing checks that the URL is `http(s)`. The lightbox does (`lightbox.js:296`); the anchors do not.
- **Reproduction (executed in DOM):** route `/ui-api/performers/5` to return a source with `source_url: "javascript:void(window.__xss=5)"`; open `#/performer/5?tab=sources`.
  - Result: `a[href^="javascript:"]` count 1.
- **Evidence for reachability (read-only):** the backend stores crawled URLs without a scheme check: `app/Services/Persist/MoviePersister.php:212,243,350,451` (`source_url`, `profileUrl`) and `GalleryPersister.php:104`. `grep` finds no `https?` or `FILTER_VALIDATE_URL` on these paths. Whether a crawled site can supply `javascript:` in its `href` was not tested.
- **Root cause:** no scheme allow-list on the UI or at persistence.
- **Proposed fix:** one helper `safeHref(u)` (allow only `^https?://`, otherwise render plain text), used on all four sites; on the server, validate the scheme when persisting `source_url`, `profile_url` and gallery URLs.
- **Proposed failing tests:** `performer sources: a non-http source_url is not rendered as a link`; `movie gallery: non-http image links are not rendered`.

### BH-frontend-008 — S3 — "today" computed in UTC

- **Where:** `views/home.js:227`: `released_to: new Date().toISOString().slice(0, 10)`.
- **Reproduction (executed):** `page.clock.install({ time: '2026-11-06T18:30:00Z' })` (= 2026-11-07 01:30 ICT), open `#/`; the request sent is `released_to=2026-11-06`.
- **Impact:** from 00:00 to 07:00 ICT the "Phát hành gần đây" panel excludes movies dated the current ICT day.
- **Root cause (confirmed).** `calendar.js` uses the browser's local date, which is right for the browser but not for a server-side day boundary.
- **Proposed fix:** build the date with `Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Ho_Chi_Minh' })`, or have the API return "today" in the site timezone.
- **Proposed failing test:** `home: recent releases use the owner's day (Asia/Ho_Chi_Minh) at 01:30`.

### BH-frontend-009 — S3 — avatar initials not escaped

- **Where:** `js/components.js:36` (`${initials(name)}`), `js/util.js:308`.
- **Reproduction (executed):** `/ui-api/performers` returns `name_romaji: "<b x"`; open `#/performers`.
  - Result: `<div class="avatar"><x< div="">…</x<></div>`: the `</div>` and the card markup are swallowed into a broken element.
- **Not an XSS:** only the first letter of each of the first two tokens reaches the output, and `<`, quotes and spaces cannot be injected. The defect is markup breakage.
- **Proposed fix:** `esc(initials(name))`.
- **Proposed failing test:** `avatar: initials of a name that starts with "<" are escaped`.

### BH-frontend-010 — S3 — clipboard fallback shows the secret in a toast

- **Where:** `js/util.js:328-330` (`catch { toast(text); }`); called from `views/admin.js:51` (new API key, `data-act="copy"`) and `views/downloads.js:111` (signed download link).
- **Summary:** the clipboard API is unavailable on non-secure origins (the app is served over `http://` on the LAN) and when permission is denied. Then the full secret or signed URL is written into a toast for 2.6 s.
- **Evidence:** by reading only; `navigator.clipboard` is `undefined` on insecure origins per the Clipboard API spec. Not executed.
- **Proposed fix:** on failure, show "Không sao chép được — chọn và sao chép thủ công" with the text selected in a field, not the raw value in a toast.
- **Proposed failing test:** `copyText: a failed copy never displays the copied value`.

### BH-frontend-011 — S3 — Crawl page claims a 30-second refresh

- **Where:** `views/crawl.js:123` ("cập nhật mỗi 30 giây") and `:139` (`mount: root => { /* refreshed by the app timer */ }`). `js/app.js:300-301` refreshes only the top-bar pill (`live()`).
- **Summary:** the table is rendered once. The comment is wrong and the page claim is false.
- **Evidence:** by reading; the 30-second timer updates `#livetxt` only.
- **Proposed fix:** re-render the table on the 30-second tick (through the same `AbortController`) or change the claim.
- **Proposed failing test:** `crawl: the table reflects a changed source status after 30 s`.

### BH-frontend-012 — S3 (size SUSPECTED) — whole-collection fetches

- **Where:** `js/marks.js:48` loads `GET /ui-me/marks` (all of the owner's marks); `js/marks.js:62` loads `GET /ui-me/library/codes` (every distinct on-disk code), at most once a minute per tab. Server side: `app/Services/Ui/UiMarksService.php:19-27` (no limit), `app/Repositories/LibraryFileRepository.php:52-60` (`distinct()->pluck('code')->all()`).
- **Summary:** the owner principle "a request costs O(page size)" is broken: both responses grow with the owner's collection and the library.
- **Size:** unknown. One read-only production query for planner row estimates returned `library_files|0` and `user_marks|0`. The stats may be stale, so no conclusion can be drawn about size.
- **Proposed fix:** a `codes?ids=` endpoint that answers for the codes on the current page, and a marks query by `subject IN (...)` for the visible items.
- **Proposed failing test:** `UI marks: the response is bounded by the requested subjects`.

### BH-frontend-013 — S3 (latent) — route code unescaped in `head()`

- **Where:** `views/player.js:136` and `:137`: `head('Phát', params.code)`. `head()` inserts its second argument raw (`components.js:20`).
- **Summary:** a `code` from the URL reaches the DOM unescaped in these branches. The backend returns 404 for unknown codes (`UiPlayerController::show`, `abort_if`), and the error path uses `errorBox`, so a normal link cannot reach it. It needs a library code that contains HTML.
- **Proposed fix:** `esc(params.code)`.
- **Proposed failing test:** `player: a route code containing HTML is rendered as text`.

### BH-frontend-014 — S3 — surrogate split in the error preview

- **Where:** `views/crawl.js:136`: `r.last_error.slice(0, 70)`.
- **Reproduction (executed in Node):** `'😀 long'.slice(0, 1)` → `"\ud83d"` (a lone surrogate, rendered as �).
- **Proposed fix:** `[...text].slice(0, 70).join('')` (code points), or `Intl.Segmenter`.
- **Proposed failing test:** `crawl: a 70-character preview never splits an emoji`.

### BH-frontend-015 — S3 — modifier-blind shortcuts

- **Where:** `js/app.js:276`: `else if (!typing && e.key === 't') toggleTheme();`. The `/` and `?` shortcuts at lines 275 and 277 have the same problem.
- **Summary:** Ctrl+T (new tab) also toggles the theme, and Ctrl+/ or Ctrl+? fire app shortcuts.
- **Evidence:** by reading.
- **Proposed fix:** require `!e.ctrlKey && !e.metaKey && !e.altKey`.
- **Proposed failing test:** `shortcuts: modifier combinations do not trigger app shortcuts`.

### BH-frontend-016 — S3 (SUSPECTED) — stale `finally` in facet refresh

- **Where:** `js/facets.js:189-201`. An aborted request still runs its `finally`: it removes `dimmed`, `aria-busy` and sets `loading.hidden = true` if the lifetime signal is not aborted, while a newer request is in flight.
- **Proposed fix:** only the latest request (compare with a generation counter) touches the loading state.
- **Proposed failing test:** `facets: a superseded request does not hide the loading state`.

### BH-frontend-017 — S3 (SUSPECTED) — preview responses out of order

- **Where:** `views/notifications.js:52-63`. Each keystroke (250 ms debounce) sends a preview POST; there is no sequence check, so an older response can finish last and overwrite the newer text.
- **Proposed fix:** keep a request sequence and drop responses that are not the latest.
- **Proposed failing test:** `notifications: a slow older preview never replaces a newer one` (route delay).

### BH-frontend-018 — S3 (SUSPECTED) — debounce survives leaving the page

- **Where:** `views/catalog.js:48` (`debounce(... go(...), 500)`); the returned cleanup (`:253`) calls only `ctl.destroy()`, not the debounce.
- **Summary:** typing in the catalog search and leaving within 500 ms can navigate back to `#/catalog/...` after the user has moved on.
- **Proposed fix:** cancel the debounce in cleanup (`clearTimeout`), or move the debounce into a closure with a `cancel`.
- **Proposed failing test:** `catalog: leaving the page cancels a pending search`.

### BH-frontend-019 — S3 (SUSPECTED) — reload after save hits the current page

- **Where:** `views/settings.js:106` (`setTimeout(() => location.reload(), 1500)`).
- **Summary:** after saving a settings group the page reloads 1.5 s later, whatever page the user is now on, and discards any input typed meanwhile.
- **Proposed fix:** re-render the group in place, or reload only if the route is still `#/settings`.
- **Proposed failing test:** `settings: saving does not reload a page the user has left`.

### BH-frontend-020 — S3 — setting inputs not labelled

- **Where:** `views/settings.js:26` (`<b>` title, not a label) and `:58-70` (inputs with `id` but no `for`/`aria-labelledby`; only some carry `aria-label`).
- **Summary:** screen readers announce unnamed text, number and select controls.
- **Evidence:** markup reading. An axe run was not performed.
- **Proposed fix:** `<label for="st-…">` around the title, or `aria-labelledby`.
- **Proposed failing test:** `settings: every setting control has an accessible name` (axe-core in Playwright).

### BH-frontend-021 — S3 (SUSPECTED) — numeric controls on the report page

- **Where:** `views/report.js:192-195`: `field.type === 'number'` gives `Number(value)`, so an empty field saves `0`. The `<select>` for `int` with `options` (`:148`) is not `type=number` and is sent as a string (`field.value`).
- **Proposed fix:** validate empty input in the browser; send numbers for numeric selects; let the server reject a string.
- **Proposed failing test:** `report: an empty interval field is not saved as 0`.

### BH-frontend-022 — S3 — unbounded `api()` cache

- **Where:** `js/api.js:7,13-15,33`. Each URL with a TTL stays in `cache` for the page lifetime; entries are never evicted.
- **Impact:** small in practice (the TTL'd URLs are few). Recorded for the owner principle "everything bounded".
- **Proposed fix:** cap the cache (e.g. 100 entries, delete expired on write).
- **Proposed failing test:** `api: the cache never holds more than N entries`.

### BH-frontend-023 — S3 — unknown `?tab=` leaves an empty panel

- **Where:** `views/movie.js:19,25-56`: the tab is taken from the query as-is; an unknown value matches no branch, so `content` stays `''`.
- **Proposed fix:** fall back to `meta` for unknown tabs.
- **Proposed failing test:** `movie: an unknown tab shows the metadata tab`.

## Out-of-scope observation (not a front-end bug)

Production Sentry, project 2 `xcrawler` (one read-only Django query, 30 days, titles filtered for UI-like errors): no browser-side issue matched. The same query showed **`TypeError: Redis::eval(): Argument #3 ($num_keys) must be of type int, array given`**, group 167, 910 events, level 40, last seen 2026-11-07 00:50 UTC, still recurring (groups 168 and 169 are the same error). This is backend and should be triaged separately.

## Checked and found OK

- **XSS payloads through escaped paths:** `<img src=x onerror=…>` in movie title, genre, performer name, maker (`#/movies` cards), and in the search heading (`#/search?q=…`): no `window.__xss` fired, text rendered as text (`components.js` `movieCard`, `search.js:192`).
- **Console and network health:** 20 routes as owner (`'', search, movies, performers, bio, stats, crawl, calendar, catalog/genres, catalog/years, settings, settings/notifications, settings/report, admin, downloads, library, media, feed, me/favourite, me/seen`): no page errors, no console errors, no HTTP ≥400 responses.
- **Mobile 390 px:** no horizontal overflow on home, search, movies, performers, stats, settings, crawl, calendar (`scrollWidth == clientWidth`).
- **Infinite list:** at 1920×1080 with small cards the second page loads without scrolling; at 1440×900 the list is scrollable and waits for the user. No stall reproduced (the stall condition needs a non-scrollable page taller than the viewport plus the 600 px root margin; not reproduced).
- **Race guards:** `render()` drops stale views by token (`app.js:186,191`); `searchBrowser` aborts on leave (`cleanup`); omnibox compares the current input value (`app.js:205`); the picker uses a generation counter (`pickers.js:118,126`).
- **Lightbox:** only `http(s)` sources (`lightbox.js:296`), Esc and focus trap work (read).
- **CSRF:** `send()` reads `meta[name=csrf-token]`, which the server injects (covered by `tests/Feature/Ui/UiTest.php`).
- **`fold`/`queryString`:** values are URL-encoded by `URLSearchParams` (no injection through filters).
- **Timezone of calendar:** the calendar uses date strings and local month arithmetic, which is correct.

## Could not verify

- **Production data sizes** for `library_files` and `user_marks` (planner estimates are 0; `n_live_tup` is stale or the tables are empty) — BH-frontend-012.
- **Production value of `report.daily_timezone`:** the UI fallback `America/Mexico_City` matches the backend default (`config javmeta_report`); the live setting was not read (no query of the settings table).
- **Backend acceptance of `javascript:` URLs** from crawled pages: no crawl fixture was run — BH-frontend-007.
- **POST duplication** for Telegram sends and deletes (BH-frontend-001): same mechanism as the GET counts; not executed to avoid sending real messages.
- **Axe / contrast / keyboard focus traps:** no axe run; the help dialog's focus behaviour and the contrast of `--mute` were not measured.
- **Screen-reader output**, real mobile touch targets, and Safari/Firefox behaviour: not tested (Chromium 1248 only).
- **Clipboard fallback on a real insecure LAN origin** (BH-frontend-010): reasoned from the Clipboard API, not run on `http://192.168.x`.

## How the evidence was produced

- Worktree `JOOXCR-bughunt-frontend`; `composer install` through `docker/ci/run`, `npm ci` in `ui-tests/qa` (both ignored by git).
- QA stack: `JAVMETA_QA_PORT=18099 docker/qa/qa-up` (the first start failed because `vendor/` was missing; it was installed and the stack restarted); torn down afterwards with `docker/qa/qa-down`. Port 8098 (`javmeta-qa2`) was not touched.
- Browser: Playwright-core 1.56.1 with Chromium 1248, one process, scenarios run sequentially. Scratch scripts are outside the repository and were removed after this report was written; no scratch tests were added to `tests/`.
- Production: 1 SQL query (`pg_stat_user_tables`, `SET statement_timeout=10000`, read-only); 1 Sentry query (read-only `Group` listing). No writes.

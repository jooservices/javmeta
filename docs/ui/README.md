# XCrawler UI: approved concept A ("XCrawler Console")

`concept-a.html` is the approved design (owner decision 2026-10-24), saved byte for byte from the Jira attachment
`xcrawler-ui-concept-A-console.html` of JOOXCR-197. Open it in a browser. Its movies, performers and numbers are fake
(a seeded generator); the real data comes from the JavMeta API. Sample B ("posters") was not chosen.

## Look
- Dark console by default, light theme optional; dense layout, compact density switch.
- Tokens (dark): background `#0b0e11`, panels `#12161b` / `#181d23`, lines `#252c35`, text `#dde4ec`, muted `#8a96a4`,
  accent teal `#4fd1c5`, ok `#5fd38d`, warn `#f0b429`, bad `#f06a6a`, info `#6ea8fe`. Light theme tokens are in the file.
- Fonts: Geist (UI) and JetBrains Mono (numbers, codes). Vietnamese UI text.
- Shell: left sidebar sitemap (208 px), top bar with the search box (omnibox, Ctrl/Cmd-K), live status pill, bell, theme and
  help buttons; phone layout (under 820 px) uses a bottom tab bar.

## Screens (views of the concept)
home (Discover), search (natural language, parsed chips), movies (filter sidebar, grid or table, infinite scroll),
movie (detail, scenes, torrents, related), actors and actor (bio facets, filmography), genres / tags / studios / series
indexes, bio hub, facet detail (every genre, tag, studio, series, source, year and bio value is a page), calendar,
stats, follows and notifications, collections (favourites, watch later, seen), crawl (per-source health), settings.
Screens that belong to later epics (download queue, library of the 20 TB disk, chat bot, AI scenes) stay hidden for now.

## Rules to keep
- Every value shown is a link to the list or page of that value.
- Filters, sort and the open record are in the URL (shareable, back button works).
- Omnibox groups suggestions (movies, performers, genres, studios, series) and keeps recent searches.
- Keyboard: `/` or Ctrl/Cmd-K search, `?` help, arrows and Enter in lists.
- Missing data shows "not known yet" (a waiting-for-crawl badge), never a blank.


## The running UI (first version)

The UI is a single page of plain ES modules served from `public/ui` (no build step): `/app` (the page, owner session
required) and `/ui/*` (static files). It reads the API through `/ui-api/<path>`, which runs `/api/v1/<path>` in the same
process for the signed-in owner (GET only; paths movies, performers, meta, status, suggest, search), so the browser never
holds an API key. Views: Discover, search, movies (filters, grid or table, infinite scroll), movie, performers,
performer, facet pages (genre, maker, series, label, year), crawl status and settings. Routes use the hash
(`#/movies?genre=Drama`), so every state is a shareable link.

Check the views without a browser: `scripts/docker/runtime node node ui-tests/render-smoke.mjs`.

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
Saved searches are per owner and browser in `user_marks`, through authenticated `/ui-me/marks` with CSRF for changes.
Saving the same name replaces the query for that browser, while another owner or the other browser keeps its own entry.
Names allow 1–80 characters; queries allow up to 1500; there are no browser-stored secrets or new environment settings.

Verification: `scripts/docker/runtime node node ui-tests/render-smoke.mjs` includes sample facets/suggestions and parameter,
escaping, picker keyboard/debounce, request cancellation, cache and saved-search interaction assertions without site login.

The Library page offers per-file preview and scene extraction buttons. **Media** (`#/media`) lists queued jobs, indeterminate progress while running, errors and retries for failed jobs. Paging and a refresh button keep the queue bounded. All changes use the owner's session and CSRF token; worker credentials are never sent to the browser.

Per-file Media actions include **Phụ đề** (local GPU Whisper) and **Dịch EN / VI** (local Ollama, after recognition). Missing prerequisites appear as job errors in the Media queue.

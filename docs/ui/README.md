# XCrawler UI

[User guide (Tiếng Việt)](../user-guide/README.md) · [Developer guide](../developer-guide/README.md) · [Testing](../developer-guide/testing.md) · [Verified documentation differences](../developer-guide/verified-differences.md)

## Running application

The UI is plain ES modules under `public/ui`, served at `/app` behind the owner login, with static files at `/ui/*`. Hash routes preserve filters and browser history. The allowlisted GET bridge `/ui-api/*` dispatches to `/api/v1/*` in-process; personal data uses `/ui-me/*`, administration/settings use `/ui-admin/*`, and writes use `send()` with CSRF. Browsers never hold an API key.

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

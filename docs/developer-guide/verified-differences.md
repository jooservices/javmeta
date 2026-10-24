# Verified documentation differences

[Guide index](README.md)

Evidence baseline: `develop` commit `0a4126c` (the branch used for this guide). This is source verification, not a live-staging audit.

| Earlier statement or UI text | Checked-in behavior and documentation action |
| --- | --- |
| UI design notes: downloads, disk library and bot are hidden future screens | NAV contains Thư viện and Tải về, and `TelegramBot` implements commands. Rewrote the UI index to separate the historical concept from the running UI. Downloads still default off. |
| UI notes: omnibox keeps recent searches and suggests studios/genres | `app.js` builds movie/performer/maker/series suggestions plus a search link; it has no recent-search store. Removed those running-UI claims. Search results separately include genre suggestions. |
| UI notes: only the original first-version views are available | Current NAV also exposes catalogs, calendar, stats, personal marks/follows, library, downloads and administration. Updated the index and user pages. |
| Cài đặt lists `g h`, `g m`, `g p`, `g c`, `g s` | `app.js` only handles Ctrl/Cmd-K, `/`, `t`, `?`, Esc and omnibox arrows/Enter. Documented only implemented shortcuts; left product code to its own ticket. |
| Crawl title: “cập nhật mỗi 30 giây” | The timer calls `live()` for the top status pill, not the crawl view. User guide instructs reloading the table. |
| Search mode “Từ khoá” implies no semantic search | It passes `understand=0`; `HybridSearchService` still runs semantic retrieval and rank fusion. Explained that this switch disables question interpretation, and that strict filtering belongs in Phim. |
| “Tôi hiểu là” appears to guarantee every interpreted filter | The keyword branch can relax to filters only or words only. The `interpretation.relaxed` field is returned but the UI does not display it. Documented the limitation. |
| Operations settings type list omitted `string_list` | Added the type and linked generated reference. Existing defaults may still read env; all new feature configuration must use settings/default files without `env()`. |
| API notes: only `/api/health` is public and every protected route uses API-key limits | `/api/health/live` and `/api/openapi.json` are public too; admin routes use the owner token and a different middleware path. Corrected the operations API guide. |
| MCP scope may appear to apply identically to both transports | HTTP middleware enforces `mcp`; stdio `McpRequestHandler::isAuthorized()` checks only whether the configured key is active. Documented the current distinction; authorization changes need a separate task. |
| Task mentions a media pipeline | This baseline stores media URL references and supports explicit downloads/file links; it has no separate media processing/capture service. Architecture shows only implemented paths. |

No application behavior was changed to reconcile these differences. No new endpoint or data query was added, so there are no new production query timings to report.

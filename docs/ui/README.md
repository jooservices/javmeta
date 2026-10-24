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

# Extension checklists

[Guide index](README.md)

Read the current neighboring implementation and write tests for the intended behavior first. Keep identifiers/comments in English and user-facing text in Vietnamese. These are implementation checklists, not permission to deploy or merge.

## Add a setting

- [ ] Add the default to the relevant `config/javmeta_*.php` file without `env()`; add no feature configuration to env files.
- [ ] Add a definition to `config/javmeta_settings.php`: group, Vietnamese label/description, type, mapped config keys, bounds/options and any required restart. Add a Vietnamese group title if new.
- [ ] Use `secret` for credentials, `string_list` for folders/networks, or the existing scalar/list types. Do not return or log plaintext secrets.
- [ ] Read `config()` after the settings provider applies values, or use `SettingsService::get()`. Do not repeatedly connect to the store in a tight loop.
- [ ] Test the default, saved override, validation, secret masking and any effect on behavior; see `tests/Feature/Settings/SettingsTest.php`. The provider skips automatic reads in tests: bind `FakeConfigStore` explicitly.
- [ ] Check the automatically generated controls in Cài đặt, loading/save/error states and restart requirements. Regenerate [settings-reference.md](../user-guide/settings-reference.md) using [the tested command](testing.md).

## Add an API filter

- [ ] Add validation/coercion in the relevant FormRequest (`MovieSearchRequest` / `PerformerSearchRequest`), including combinations, ranges, empty and invalid values.
- [ ] Pass the validated filter into the search service and repository. Use bound values, existing indexes, and one indexed branch per searchable column as in `PerformerRepository` and `MovieRepository::performerIdsMatching`; avoid broad unindexed OR scans.
- [ ] Preserve cursor sort semantics, count/facet consistency and page bounds. Update `MovieFacetsController` / `PerformerFacetsController` as needed, not just the list endpoint.
- [ ] Add matching filters to MCP validation/definitions if exposed there; UI needs `filterState.js`, `filterControls.js`, chips, picker/facet handling and render tests.
- [ ] Update `resources/openapi/javmeta.json`, the [user filters](../user-guide/movies.md), [UI notes](../ui/README.md) and [MCP inputs](../03-operations/mcp.md).
- [ ] Cover intersections, unknown/missing values, any/all behavior, paging, facets, authorization and invalid input with unit/feature tests. Measure each new query on the 26,000-movie / 26,000-performer dataset with QA's read-only performance workflow; record timings and query plans. Do not claim SQLite timings as production evidence.

## Add an MCP tool

- [ ] Add its name, description and input schema to `app/Services/Mcp/McpToolService.php`, and wire its handler to existing services/resources rather than a separate business implementation.
- [ ] Validate all arguments before invoking the service. Match the corresponding REST field names/types; include defaults and required inputs.
- [ ] Preserve JSON-RPC handling in `McpRequestHandler` and `McpHttpController`; the HTTP route requires an active key with `mcp` scope. MCP scope grants tool access; it does not additionally enforce the corresponding REST family scopes per tool.
- [ ] Update the tool input table in `docs/03-operations/mcp.md`. Add parity, invalid-argument and successful HTTP/handler tests; check `result.isError` as well as HTTP status.
- [ ] Add a usage example. If a new `/api` route is introduced, document it in OpenAPI and run the route-contract test.

## Add a UI view

- [ ] Add a plain ES module under `public/ui/js/views` exporting `render({params, query})`, returning `html` and an optional `mount` cleanup function. Use existing components and escaped text.
- [ ] Register its hash route and exact Vietnamese navigation label in `public/ui/js/app.js`. Preserve useful browser Back/Forward and URL state.
- [ ] Read through `api()` / `admin()`; owner changes use `send()` with CSRF. Never embed API keys. Add an owner-protected bridge/backend route only when necessary.
- [ ] Handle loading, empty, error, expired-session and retry states. Abort stale requests and remove timers/listeners on navigation.
- [ ] Use existing responsive CSS, filter drawer and accessible controls; verify 375 px width with QA. Extend `ui-tests/render-smoke.mjs` for every new UI behavior and unsafe-text case; backend routes require feature tests.
- [ ] Add a short user page with the exact menu/control labels and troubleshooting; link it from both guide indexes and `docs/ui/README.md`.

## Add a source

- [ ] Confirm CrawlerX supports the site and crawl types. `CrawlerxClient` delegates site parsing/fetch choices to the package; do not copy a parser into JavMeta.
- [ ] Follow `config/javmeta_sources.php`, `Source` and `Crawler/SyncSourcesCommand`: define the slug/default behavior and synchronize into database source rows using the reviewed operational procedure. Inspect existing rows before changing source configuration.
- [ ] Use `SourceNormalizerRegistry` and the default normalizer where sufficient; add/register a special normalizer only for actual source differences.
- [ ] Keep listing, detail, performer and gallery capabilities truthful. Preserve source URL/provenance, normalization, code/performer identity and soft-404/error classification.
- [ ] Test captured fixtures with the CrawlerX seam; cover missing codes, empty pages, bad payloads, retry/pacing and persister behavior. Use fixtures, not uncontrolled live crawls in unit tests.
- [ ] Apply existing source pacing, inflight and browser limits. New switches/addresses belong in settings definitions and UI, not env files. Login cookies go through the encrypted cookie store/admin endpoint; never commit fixture cookies.
- [ ] Update source operations documentation and `SourceParityChecker` expectations as appropriate; ask QA to verify one authorized live path, source health and queue recovery before enabling normal ingestion.

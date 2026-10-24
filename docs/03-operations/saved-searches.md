# Owner saved searches

The movie and performer UI stores named filters in the existing owner-scoped `user_marks` table. No migration, new API
route, MCP tool, service or environment setting is needed. The public catalog filter and facet APIs are unchanged.

`GET /ui-me/marks?kind=saved_search&subject=movies` (or `performers`) lists the owner's saved filters.
`POST /ui-me/marks` uses the owner session and the existing CSRF middleware:

```json
{
  "kind": "saved_search",
  "subject": "movies",
  "key": "{\"name\":\"Co-stars\",\"query\":\"actress_id=1%2C2&genre=Drama\"}",
  "on": true
}
```

`key` is JSON with a non-empty string `name` up to 80 characters and a string `query` up to 1500 characters. The outer key
limit is 2000 characters for saved searches only; existing marks retain their 300-character limit. Names are trimmed and
JSON is canonicalized. A transaction locks the owner row, then replaces entries with the same owner, subject and name;
the existing unique key index still prevents identical rows. `on: false` deletes the matching name and query only, so a
stale browser menu cannot delete a newer replacement. Owner and browser scopes are independent.

Only `user_marks` and an indexed owner-row lock are added to the write path. No new movie/performer table queries are added
in PHP; the UI calls the already available facets, suggest, catalog and performer detail endpoints. Empty-state relaxation
is bounded to three facet calls. See [UI search and filters](../ui/README.md#search-and-filters) for all URL parameters.

Regression: `scripts/docker/runtime php@8.5 php vendor/bin/phpunit tests/Feature/Ui/UiMarksTest.php` and
`scripts/docker/runtime node node ui-tests/render-smoke.mjs`. QA should additionally check the drawer and focus behavior
at 375 px with a browser; the render harness has no layout engine.

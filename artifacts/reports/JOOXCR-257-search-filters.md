# JOOXCR-257 search and filters verification

Machine: WSL `Workstation`. Branch: `JOOXCR-257-search-filters`, based on `origin/develop` at `0a1e181`.

## Verified changes

- Movies and performers share URL-backed filters, individual removable chips, drawer controls, all API sorts, grid/table
  rendering and page sizes. Existing catalog and filmography restrictions are preserved and included when saving searches.
- Performer suggestions debounce 250 ms, require two characters, support keyboard selection, cache resolved names and return
  focus to the picker. Catalog suggestions cache for 120 seconds. Facet requests abort on navigation; cached facets remain
  dimmed while loading. Zero-result suggestions use at most three relaxed facet calls.
- Saved searches use owner-scoped marks and CSRF. Validation limits are covered, names replace within owner/browser scope,
  and stale deletions preserve newer replacements. Existing mark limits and unique index remain intact.
- No new public API route, MCP parameter, movie/performer query implementation, migration or environment setting.

## Commands and results

Commands below ran from the task worktree using the shared runtime wrapper at
`/home/joos/Workspaces/scripts/docker/runtime`:

- `runtime php@8.5 php vendor/bin/phpunit tests/Feature/Ui/UiMarksTest.php`: **5 tests, 53 assertions passed**.
- `runtime php@8.5 php vendor/bin/phpunit`: **1005 tests, 8004 assertions passed**, 1m47s.
- `runtime node node ui-tests/render-smoke.mjs`: **22 views passed**, plus search filter unit and mounted event assertions
  (parameter forwarding, escaping, checkbox counts, facets, empty suggestions, picker debounce/keyboard, request abort,
  catalog cache, saved search CSRF/save/apply/delete, URL chip removal and mounted movie list).
- `runtime php@8.5 php vendor/bin/pint app/Http/Controllers/Ui/UiMarksController.php tests/Feature/Ui/UiMarksTest.php --test`: passed.
- `runtime php@8.5 php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --sequential
  app/Http/Controllers/Ui/UiMarksController.php tests/Feature/Ui/UiMarksTest.php`: passed.
- Targeted PHPStan level 5 on these two PHP files: passed with the test encryption key, in-memory SQLite and array cache
  supplied to its bootstrap process. An initial invocation without test bootstrap environment failed for a missing key;
  no application environment file was changed.
- `git diff --check`: passed. No PHPCS or PHPMD was run, as requested.

## Limits and unrelated workstation findings

- No browser tool is exposed in this session. Responsive drawer markup at width 375 is tested, but pixel layout, scrolling
  and real browser focus remain for QA's browser check. No real-site login was attempted.
- Sonar is not configured in this project (`sonar-project.properties` is absent); no Sonar service was changed.
- New PHP queries touch only marks and an indexed owner row; no new query against the 26,000-row catalog is introduced.
  Existing backend filter/facet SQL was left untouched, so no synthetic catalog was seeded or timed.
- The company GitHub helper exits because the legacy sibling `Sites` path is missing. Direct `gh api user --jq .login`
  verified `soulevilx`; HTTPS Git uses `gh auth git-credential` without changing remote/global configuration.
- SSH Git fails on `/etc/ssh/ssh_config.d/20-systemd-ssh-proxy.conf` ownership/permissions. OS configuration was left alone.
- No merge or deployment was performed.

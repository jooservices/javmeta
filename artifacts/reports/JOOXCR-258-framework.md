# JOOXCR-258 framework validation

Machine: Workstation (WSL), 2026-10-24. Generated media only; no 20 TB disk, live migration or deployment.

Commands run from the task worktree:
- `scripts/docker/runtime php@8.5 php vendor/bin/phpunit tests/Feature/Media tests/Feature/Api/OpenApiContractTest.php tests/Unit/Deploy/EnvironmentsScriptTest.php`: 13 tests / 65 assertions before adding the valid-scene ingest regression.
- `scripts/docker/runtime media python -m pytest -c tools/media-worker/pytest.ini tools/media-worker/tests -q`: 3 passed, including a generated red/blue 4-second input, previews, a cut at 2 seconds and unchanged source bytes.
- `scripts/docker/runtime node node ui-tests/render-smoke.mjs`: 22 existing views plus queue failed/empty/offline states at innerWidth 375 pass.
- `scripts/docker/runtime php@8.5 bash tests/Shell/staging.test.sh`: PASS; includes media unit in dry run and restart order.
- `scripts/docker/runtime php@8.5 php vendor/bin/pint --dirty`: PASS.

Static analysis needs a non-secret test APP_KEY and SQLite in-memory connection to bootstrap this isolated checkout. Full PHPStan reports existing findings in FeedPoolCommand, ApiKeyController, CatalogController, MovieRelatedController, CrawlQueueService, DownloadService and HybridSearchService. No findings in the new media classes. ApiKey scopes now has its array PHPDoc because the changed public scope contract requires it.

Out-of-scope infrastructure: company GitHub helper refuses all checks because its sibling Sites directory does not exist. Direct `gh api user --jq .login` verifies soulevilx. SSH transport fails on system SSH configuration file ownership; HTTPS with task-scoped gh credential helper successfully fetches origin/develop. No OS/global changes made.

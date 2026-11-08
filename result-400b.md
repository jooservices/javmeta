# JOOXCR-439 / merge gates (result-400b)

**Machine:** WSL · **Branch:** `JOOXCR-373-readiness` · **Date:** 2026-11-07  
**Tracker:** JOOXCR-439 (JOOXCR-400 key was already taken by ROOKIE; commits reference JOOXCR-439)

## Verdict

Merge gates for per-file coverage and browser E2E on the readiness branch are **green**. Nothing was pushed.

## Final numbers

| Gate | Result |
|---|---|
| `composer lint` (pint, phpcs, phpmd, phpstan, php-cs-fixer) | Pass |
| `composer lint:phpstan` | Pass (0 errors) |
| Unit/Feature with clover (`composer test:coverage`) | **2441 passed**, 1 skipped, 23 592 assertions · ~340 s |
| Aggregate coverage (`composer coverage:check`) | **94.54%** (18 663/19 740) · floor 90% |
| Per-file coverage (`tools/quality/coverage-per-file.php 85` on `build/coverage.xml` + `build/integration.xml`) | **95.68%** overall (18 825/19 674 statements across **468** measurable app files) · **0 files below 85%** |
| Integration (`docker/ci/integration`) | **122** + **1** tests, 1 418 assertions, 1 skipped · exit 0 |
| Render smoke (`ui-tests/render-smoke.mjs`) | 37 views, 30 docs pages, 24 menu labels, 81 links |
| Browser E2E (`QA_BASE=http://localhost:18099 node ui-tests/qa/run.mjs main`) | **31/31** scenarios, 612 steps |

## Coverage decision

PHPUnit previously excluded `app/Console/Commands` (80 files) and `app/Services/Crawler/CrawlerxClient.php` from `<source>`. Those excludes were removed from `phpunit.xml` and `phpunit.integration.xml`. Fresh measurement includes them.

- All **80** command files and `CrawlerxClient.php` (**91.7%**) are ≥85%.
- Characterisation tests live under `tests/Feature/Console/Commands/` (one class per command group) plus `tests/Feature/Crawl/CrawlerxClientTest.php`.
- `tools/quality/coverage-per-file.php` is new here; branch `JOOXCR-402-quality-gate` does not contain it. Wired as `composer coverage:per-file`.

### Seams / thin spots (not exclusions)

- `McpServeCommand`: stdin/STDOUT covered via temporary `php://` wrapper and STDOUT write filter; `fopen(stdin) === false` not reachable in CLI.
- `RenderNotificationCommand`: `catch (InvalidArgumentException)` hit by removing `--sample` from the definition because registry/renderer/validator are final.
- `HorizonReconcileWorkersCommand`: restart-required path mocked behind Redis; scale-and-apply stays in integration.
- Thin but ≥85%: `PublishMetricsCommand` ~86.5%, `EgressReportCommand` ~87.5%, `BackfillEmbeddingsCommand` ~87.6%.
- `CrawlerxClient` unsupported-URL path not covered offline (crawlerx walks every fetch method ~20 s).

## QA port

Orphan `docker-proxy` still holds host **8099**; it was not killed. `JAVMETA_QA_PORT` (default 8099) configures `docker/qa/compose.qa.yml`, `qa-up`, and docs. This run used **18099**. Project `javmeta-qa2` / **8098** was not touched.

## E2E fixes (real defects, checks kept)

- Phone drawer stacks above the top bar (`#side`/`#sidescrim` z-index 46/45, `.top` 20) so “Tìm kiếm” is clickable; menu opener correctly sits under the drawer.
- `togglePhoneMenu` waits for open transform identity; dismiss taps `#sidescrim`.
- Report switch assert uses `attached` (hidden checkbox); Settings null→`''`; search/crawl/guest banner and calendar date fixes as needed for honest scenarios.

## Commits

See git log on `JOOXCR-373-readiness` after this handoff (subjects end with `(JOOXCR-439)`). Linked from JOOXCR-439 comments.

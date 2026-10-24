# JOOXCR-263 documentation verification

Baseline: `develop` at `0a4126c`. Scope: Vietnamese owner guide, English developer guide, generated settings reference, navigation links and documentation checks. No product behavior, routes, queries, deployment or merge changed.

## Verification

- Complete PHPUnit suite: **1011 tests, 8303 assertions**, passed (69.423 seconds, SQLite in memory).
- New settings-reference and cookbook tests: **3 tests, 282 assertions**, passed.
- OpenAPI/MCP focused check: **20 tests, 145 assertions**, passed.
- UI render smoke: **22 views**, existing filter interaction checks, **27 guide pages / 23 menu labels / 75 local links**, passed. This is not a browser screenshot test.
- Cookbook: **25 curl requests** executed against a loopback HTTP capture server with fixture keys, and the same request manifest exercised against Laravel/SQLite. No staging requests or live admin changes.
- Mermaid: both diagrams parsed and rendered to SVG with Mermaid 12.2.0, jsdom 30.1.3 and SVGDOM 0.1.29 in the shared Node runtime; PNG previews inspected. Dependencies/previews remain task-local under `.tmp/JOOXCR-263/`, outside the commit. No browser or new container was launched.
- Staged-diff Gitleaks scan: passed, no leaks. Whole-directory dependency matches are discussed below.
- New PHP files: Pint, PHP-CS-Fixer dry run, PHPStan level 5 and syntax checks passed. PHPStan used testing mode, SQLite and the fixture encryption key from `phpunit.xml` to avoid booting against live stores.
- Settings reference generated from schema only; no application bootstrap, saved values or secrets are read by the generator.

All executable blocks added to the guides were run or copied from the exercised cookbook script. The operational command names inside generated settings descriptions come unchanged from the schema; library scan, AI checks and embedding-switch paths also have existing fixture-backed feature tests. No real library scan or index rebuild was performed for documentation.

## Review notes

[Verified differences](../../docs/developer-guide/verified-differences.md) lists the corrected stale documentation and remaining code/UI discrepancies, including search relaxation, missing g-prefix shortcuts, crawl-table refresh, HTTP versus stdio MCP scope enforcement, legacy env defaults and the absent separate media pipeline.

The whole-directory secret scan reported 12 generic matches exclusively under installed `vendor/` fixtures/source/PHAR files. They are outside this patch; no dependency files were changed. Review the staged-diff scan for this change separately. No scanner finding from dependencies is evidence of a credential in these guides.

GitHub CLI authentication passed as `soulevilx`. SSH transport was unavailable (system SSH configuration permission error, with no user SSH config present); Git network operations use HTTPS with the existing gh credential helper for this command only. No shared authentication or configuration was changed.

The suite does not establish current staging availability, pixel-perfect 375 px layout, live Telegram delivery, or production query timings. Those are outside this documentation-only change; no new endpoint query was added. CI is checked separately on the PR by QA.

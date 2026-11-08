# JavMeta performer UTF-8 persistence repair

Date: 2026-10-24 (Asia/Bangkok)  
Scope: `JOOservices/javmeta`, WSL main clone running the JavMeta staging/control services.

## Verified cause

Two recent `App\\Jobs\\FetchPerformerDetailJob` failures for the `minnanoav` source returned PostgreSQL SQLSTATE `22021`: invalid byte sequence for UTF-8 (`0xa7`). PostgreSQL, Redis, MongoDB, Elasticsearch, the API health endpoint, Horizon, and the scheduler were healthy during the investigation. The failure was malformed crawler text reaching persistence.

## Repair

`app/Services/Normalize/PerformerNormalizer.php` now scrubs crawler-provided strings with PHP `mb_scrub(..., 'UTF-8')` and removes NUL bytes before building a `PerformerDraft`. This covers persisted scalar fields, tags, aliases, biography text, and nested extra metadata.

## Apply and verification

- PHP syntax check passed with `php -l`.
- Restarted `javmeta-horizon` to load the normalizer change; Horizon returned to active/running and the API health endpoint remained `ok`.
- Crawl queue row `61416` (`minnanoav`, `performer_detail`) completed on attempt 3 after the Horizon restart.
- Older row `46047` had the same failure and was requeued with a guarded, row-specific transition after the fix; it completed on its first retry. Its prior failed-job records were retained.
- No new failed job appeared during follow-up; the latest failure timestamp remained 07:26:54 UTC (270 historical failed-job rows total).
- The scheduled application smoke check had passed before the repair. No test suite was run.

## Reproduction and verification commands

```bash
cd /home/joos/Workspaces/JOOservices/projects/javmeta
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php artisan javmeta:smoke --no-ansi
/home/joos/Workspaces/scripts/docker/runtime php@8.5 php -l app/Services/Normalize/PerformerNormalizer.php
systemctl --user show javmeta-horizon javmeta-scheduler -p ActiveState -p SubState --no-pager
curl -fsS --max-time 20 https://jav.soulevil.com/api/health
```

# Staging runbook (WSL)

Staging runs on the WSL workstation (`ssh wsl`, user `joos`), in the clone `~/Workspaces/JOOservices/projects/javmeta`. Public address: https://jav.soulevil.com (Cloudflare, then the gatekeeper HAProxy, then WSL port 18485). Every command below runs on WSL.

Shorthand used here:

```bash
cd ~/Workspaces/JOOservices/projects/javmeta
A="docker exec -u 1000:1000 -w $PWD -e HOME=/tmp workspaces-cli-php-8.5 php artisan"
```

## What runs

| Part | How | Check |
|---|---|---|
| Queue workers | `javmeta-horizon.service` (systemd user unit) | `systemctl --user status javmeta-horizon` |
| Scheduler (exactly one) | `javmeta-scheduler.service` | `systemctl --user status javmeta-scheduler` |
| Daily backup | `javmeta-backup.timer` at 02:30 | `$A javmeta:backup-status` |
| Web | the shared web container on port 18485 | `curl -H 'Host: jav.soulevil.com' http://192.168.2.10:18485/api/health` |
| Data | shared PostgreSQL, MongoDB, Elasticsearch, Redis, OpenObserve | `$A javmeta:smoke --json` |

## Start, stop, restart

```bash
systemctl --user restart javmeta-horizon javmeta-scheduler   # after a code or .env change
systemctl --user stop javmeta-scheduler                       # stops new work being queued
systemctl --user stop javmeta-horizon                         # stops the workers
```

Deploy a new version with `deploy/staging.sh`. It refuses a dirty clone and takes a backup, then fetches `develop` and runs the UI render smoke on that exact commit in an isolated temporary directory through the shared Node runtime (`scripts/docker/runtime node node ui-tests/render-smoke.mjs`). This happens before updating the served clone or stopping any services; a broken UI leaves the running release untouched. The candidate directory is removed on exit.

After that gate passes, the script stops the active workers, fast-forwards to the tested commit, installs dependencies and runs migrations. Immediately after migrations it runs `php artisan settings:import-env` through the same PHP 8.5 runtime as other artisan commands. This idempotent import stores the current environment values once. It then sets up search, clears the path caches without flushing queues, restarts services and runs the existing smoke checks.

Finally it waits up to 90 seconds for passing Horizon and scheduler heartbeat checks, using `staging:readiness --no-record` while waiting, then requires a successful `php artisan staging:readiness` report saying `Staging is READY`. The deadline also bounds each readiness invocation. A timeout or non-READY report prints the failing output and exits non-zero through the existing rollback: return code to the previous commit, reinstall its dependencies and start the previously active units. The database backup is restored only if the migration itself did not finish. Read the deploy log before any further recovery.

`deploy/staging.sh --dry-run` prints all three gates, including the wait, without running them. Emergency bypasses must be explicit: `JAVMETA_SKIP_UI_SMOKE=1 deploy/staging.sh` skips only UI smoke; `JAVMETA_SKIP_READINESS=1 deploy/staging.sh` skips only the final readiness gate and wait. Each logs a loud `WARNING` with `BYPASSED`; other values do not bypass anything. Neither skips settings import or the existing deployment smoke checks. These are deployment process overrides, not application settings. Never edit files in the clone by hand: make a Jira ticket, a branch and a pull request.

## Is it healthy?

- `$A staging:readiness` (also `--json`): one PASS or FAIL per check with the cause and the first thing to do; exits non-zero on any FAIL. It runs every hour, keeps the last 30 runs in `storage/app/private/monitoring/readiness-history.jsonl` and tells Telegram once when it turns bad. Readiness limits are in `config/javmeta_readiness.php`; the source fallback age is `JAVMETA_WATCHDOG_SOURCE_STALE_SECONDS`. Sources with a manifest `cycle_delay_seconds` become past due after that cycle plus one fresh-listing sweep.
- `$A crawler:sources`: one row per source with the switch, the current gap and the last success.
- `$A crawler:failures`: the latest failed jobs with their reason.
- The Operations, Data quality and Crawl debug dashboards in OpenObserve (stream `javmeta_logs` and the metric streams); alerts go to Telegram.
- The hourly Telegram report shows total movies and performers and the change since the previous report. No report for two hours means the scheduler is down.
- https://jav.soulevil.com/app (owner login) shows counts, ingest per hour and every source.

## Failures and recovery

| Symptom | Cause and fix |
|---|---|
| A source shows a long gap (up to 600 s) and no recent success | The site blocks us (rate limit, challenge). The system slows down on its own; look at the error with `crawler:failures`. Do not switch it on and off repeatedly. If it stays blocked, open a CrawlerX ticket. |
| Switch a source off or on | `$A crawler:source-toggle <slug>`; the switch lives only in the `sources.enabled` column. A source that needs a login (avfan) starts off. |
| Jobs stay `claimed` for a long time | A worker died. `crawler:reclaim` runs every minute and frees them; run it by hand to force it. |
| Queue grows without bound | More pending than the workers and gaps allow. Check Horizon, then the per-source gap; lower `JAVMETA_FEED_POOL_LIMIT` in `.env` and restart the units. |
| Failed jobs with `22021` (invalid UTF-8) | Fixed in the normalizer (JOOXCR-248). If it returns, find the source in `crawler:failures` and open a ticket. |
| Smoke check fails right after a deploy | Wait a minute (workers restart) and run `$A javmeta:smoke --json` again; the deploy script retries once itself. |
| UI render smoke gate fails | The candidate was rejected before changing the served code or services. Read the UI assertion in the deploy log, fix it in a PR and retry. `JAVMETA_SKIP_UI_SMOKE=1` is an explicit emergency bypass with a warning. |
| `settings:import-env` fails | Deployment exits through the existing rollback. Check the import error and settings database access in the log; the import is idempotent and must succeed on retry. |
| Readiness gate times out or reports NOT READY | Read the printed failing checks and recovery actions. The script exits non-zero and invokes its existing previous-commit rollback; successful migrations are not undone. Inspect Horizon, scheduler heartbeat and the deploy log before retrying. `JAVMETA_SKIP_READINESS=1` explicitly bypasses this gate with a warning. |
| `.env` change needed | Edit in place and keep its ACL (`user:www-data:r--`); never `chmod`. Restart the two units afterwards. |
| Prove it recovers on its own | `deploy/chaos-check.sh` kills Horizon and the scheduler with SIGKILL and waits up to 10 minutes for `staging:readiness` to return to READY. Measured 2026-10-24: back to READY in under one minute (systemd restarts the units, `crawler:reclaim` frees jobs of dead workers). |
| Restore the database | Follow `docs/03-operations/backup.md` (`staging-restore-check.sh` proves a backup restores). |
| The clone is dirty and the deploy refuses | Someone edited a file directly. Move the change into a ticket and a pull request, then `git checkout -- <file>`. |
| Similarity search does not work | The Ollama or embedder service is not reachable from WSL; the keyword search keeps working. |

## Keys and access

API keys are made on the Admin page of the UI (Quản trị) or with `POST /api/v1/admin/keys` and the owner token. A key may be limited to scopes (`movies`, `bulk`, `performers`, `catalog`, `status`, `mcp`) and has its own per-minute limit; the same page shows its usage. The contract is at `/api/openapi.json`; the MCP guide is `docs/03-operations/mcp.md`.

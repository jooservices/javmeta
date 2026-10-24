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

Deploy a new version with `deploy/staging.sh` (it refuses a dirty clone, takes a backup, pulls, migrates, restarts both units and runs the smoke check). Never edit files in the clone by hand: make a Jira ticket, a branch and a pull request.

## Is it healthy?

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
| `.env` change needed | Edit in place and keep its ACL (`user:www-data:r--`); never `chmod`. Restart the two units afterwards. |
| Restore the database | Follow `docs/03-operations/backup.md` (`staging-restore-check.sh` proves a backup restores). |
| The clone is dirty and the deploy refuses | Someone edited a file directly. Move the change into a ticket and a pull request, then `git checkout -- <file>`. |
| Similarity search does not work | The Ollama or embedder service is not reachable from WSL; the keyword search keeps working. |

## Keys and access

API keys are made on the Admin page of the UI (Quản trị) or with `POST /api/v1/admin/keys` and the owner token. A key may be limited to scopes (`movies`, `bulk`, `performers`, `catalog`, `status`, `mcp`) and has its own per-minute limit; the same page shows its usage. The contract is at `/api/openapi.json`; the MCP guide is `docs/03-operations/mcp.md`.

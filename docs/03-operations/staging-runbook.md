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

The native `deploy/staging.sh` entry point now delegates to the manifest-driven
wizard, with no implied repository, branch or target. It does **not** update the
legacy served WSL clone or manage its user units. Keep that existing topology
under its operator runbook until a deliberate node migration is approved; the
wizard does not migrate or restart it automatically.

For a new native staging node, prepare private service answers and use:

```bash
bash deploy/staging.sh staging --config /private/staging.json --repo "$REPO_URL" --ref "$REF" --target "$RELEASE_ROOT" # preview
bash deploy/staging.sh staging --config /private/staging.json --repo "$REPO_URL" --ref "$REF" --target "$RELEASE_ROOT" --apply
```

Follow the [native installation guide](../../deploy/production/README.md) for
backup attestation, service checks, role-specific units, an explicit scheduler
cutover, readiness and code/env rollback. Database migrations are forward-only;
no automatic database restore is attempted. The former `JAVMETA_SKIP_UI_SMOKE`
and `JAVMETA_SKIP_READINESS` staging overrides do not apply to the wizard.

## Is it healthy?

- `$A staging:readiness` (also `--json`): one PASS or FAIL per check with the cause and the first thing to do; exits non-zero on any FAIL. It runs every hour, keeps the last 30 runs in `storage/app/private/monitoring/readiness-history.jsonl` and tells Telegram once when it turns bad. Readiness and watchdog thresholds are editable in Cài đặt. Sources with a manifest `cycle_delay_seconds` become past due after that cycle plus one fresh-listing sweep.
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
| Queue grows without bound | More pending than the workers and gaps allow. Check Horizon, then the per-source gap; lower `queue.feed_pool_limit` or adjust worker settings on Cài đặt. |
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

# JOOXCR-388 — Production monitoring continuation

**Status:** C, B6, D diagnostics and E completed. A and B1–B5 were already complete before this continuation and were not repeated. Final verification: 2026-11-05 17:33 UTC (2026-11-06 00:33 Asia/Bangkok).

## Done

- Verified the partial C state on `.4`: the documented backup directory existed and was empty; HAProxy exporter binary, unit and listener were absent. Installed and started the new HAProxy exporter, restricted to Prometheus `.10`.
- Installed and started the new blackbox exporter on `.10`, LAN-bound and firewall-restricted to Prometheus itself. All four LAN HTTP checks and four TLS edge checks returned HTTP 200: Sentry, OpenObserve, SonarQube, FlareSolverr and XCrawler `/up` as configured.
- Reused the already-running Elasticsearch exporter on `.15`; constrained its ingress to `.10`. Updated the existing read-only `exporter_monitor` role with `view_index_metadata`, the additional read privilege needed for exporter alias queries. Exporter metrics then scraped successfully.
- Added Prometheus scraping for HAProxy, Elasticsearch exporter and OpenObserve `/metrics`; added LAN HTTP and public TLS probes. Sentry did not expose Prometheus exposition at `/metrics` or `/_metrics`, so its health endpoint is probed instead.
- Added severity-labelled Redis, MongoDB, Elasticsearch, HAProxy, HTTP/TLS and certificate alerts with runbook annotations. The Redis, MongoDB, Elasticsearch, HAProxy and Blackbox selections cite and adapt [samber/awesome-prometheus-alerts](https://samber.github.io/awesome-prometheus-alerts/rules); license/source comments are in the rule files. Added Redis checks for an unset `maxmemory`, configured-memory pressure and rejected connections; MongoDB exporter/server health and open cursors; and 20-day / 3-day TLS certificate thresholds.
- Completed B6 using the live Alertmanager API. One clearly labelled TEST notification was sent for critical, warning, resolved and info digest paths. The test alerts were resolved or silenced and are no longer active. Telegram notification metrics increased; the final counter is 31 with all Telegram failure counters at 0. Alertmanager's temporary test configuration was restored byte-for-byte to the saved configuration.
- Ran D diagnostics only. No PostgreSQL, Elasticsearch or Redis setting was changed.
- Created and read back missing subtasks under JOOXCR-373: JOOXCR-387 (A), JOOXCR-388 (B), JOOXCR-389 (C), JOOXCR-390 (D), and JOOXCR-391 (E). All have the `aiflow-ready` label and remain in Backlog; no workflow transitions were requested.

## Final audit

- Prometheus on `.10`: config reload success `1`; 37/37 targets up; 169/169 alert rules healthy; no rule errors.
- Alertmanager on `.10`: ready; `amtool check-config` passed; reload success `1`; 5 active alerts; Telegram failed counters `0`.
- Current firing alerts: `HaproxyBackendDown` critical for `aicc-web`, `flickr-web` and `jav-web`; `PostgresqlTooManyConnections` warning; `Watchdog` info. Pending alerts: `RedisMaxmemoryNotConfigured` warning and `XcrawlerEgressIpShared` info.
- The HAProxy backend alerts report the three backends DOWN (`L4TOUT` in the exporter health checks). These remain the primary unresolved service condition.
- No main production service was restarted. Prometheus and Alertmanager were reloaded with SIGHUP only. No ESXi host was accessed.

## D findings and proposals — no changes applied

### PostgreSQL `.12`

Read-only `pg_stat_activity` inspection, excluding the diagnostic connection, found 130 client sessions: 129 idle and one idle-in-transaction. `max_connections=150`; the `xcrawler` role limit is 120 and it held 107 sessions. The `xcrawler` sessions originated from `.20` (37), `.21` (34) and `.22` (36); `application_name` was empty. The idle-in-transaction session was `xcrawler` from `.22` and had just entered that state at the sample. Other significant groups were SonarQube 20, Infisical 2 and exporter 1.

The checked Javmeta PostgreSQL config sets a two-second PDO timeout and does not enable persistent PDO connections. The three live nodes each had 17 Horizon queue worker processes plus a Horizon master. Horizon concurrency is configurable by queue and node at runtime. **Proposal:** review the per-node worker overrides against the 120-role limit, and add a queue-loop cleanup that disconnects the `pgsql` PDO connection between jobs after transactions finish. That preserves reuse during a job but releases sockets while a long-lived worker waits. Add a regression test and monitor `pg_stat_activity` before/after before rollout. Laravel documents queue loop/after hooks and DB monitoring; see its [queue events](https://laravel.com/docs/12.x/queues#job-events) and [`db:monitor`](https://laravel.com/docs/12.x/database#monitoring-your-databases) guidance.

### Elasticsearch `.15`

Observed `vm.swappiness=60`, 8 GiB swap configured with about 2.78 GiB used, and the Elasticsearch Java process with about 2.70 GiB `VmSwap` and 14.50 GiB RSS. `bootstrap.memory_lock` is unset (default false); systemd `LimitMEMLOCK` is 8 MiB. Exporter heap metrics were 5.90 GB used of 16.83 GB maximum (5.50/15.67 GiB). **Proposal:** during a maintenance window, set `vm.swappiness=1`, enable `bootstrap.memory_lock: true`, and set the Elasticsearch unit `LimitMEMLOCK=infinity`; after the required Elasticsearch restart, verify `mlockall` and swap metrics. Elastic documents these options in [Disable swapping](https://www.elastic.co/docs/deploy-manage/deploy/self-managed/setup-configuration-memory). No restart was performed.

### Redis `.14`

Redis `INFO` reported 57.73 MiB used, 127.04 MiB peak, 331 connected clients, zero blocked clients, zero evicted keys, `maxmemory=0`, and `maxmemory-policy=noeviction`. Host RAM was 32,867,528 KiB total with about 30.58 GiB available; no swap was in use. **Proposal:** evaluate a 2 GiB `maxmemory` cap with `noeviction`, then alert on approaching the cap and on OOM write errors. The cap is roughly 16× the observed peak and the host has substantial available RAM. `noeviction` protects queue keys from eviction but Redis rejects writes after the cap, so observe queue backlog and write errors before making it permanent. Redis documents this behavior in its [memory optimization](https://redis.io/docs/latest/operate/oss_and_stack/management/optimization/memory-optimization/) and [eviction policy](https://redis.io/docs/latest/develop/reference/eviction/) guidance. This proposal was not applied.

## Files changed in the worktree

- `ops/monitoring/blackbox/config.yml`
- `ops/monitoring/prometheus/prometheus.yml`
- `ops/monitoring/prometheus/rules/11-redis.yml`
- `ops/monitoring/prometheus/rules/12-mongodb.yml`
- `ops/monitoring/prometheus/rules/13-elasticsearch.yml`
- `ops/monitoring/prometheus/rules/14-haproxy.yml`
- `ops/monitoring/prometheus/rules/15-blackbox.yml`
- `result-388.md`

`phpstan.neon` was already modified before this continuation; it was not edited or staged.

## Validation and skipped checks

- Local offline Prometheus 3.13.4 `promtool check rules`: all 15 files and 169 rules passed.
- On `.10`, the real `/etc/prometheus/prometheus.yml` passed `promtool check config`, and all 15 live rules files passed `promtool check rules` before SIGHUP. After SIGHUP, readiness, reload success, targets and rule health were verified.
- Blackbox exporter config check passed locally and on `.10`; exporter metrics, all eight probes and ES/HAProxy/OpenObserve scrape targets were verified live.
- Alertmanager `amtool check-config` passed on the restored live file; Telegram delivery/failure metrics were checked after the live API tests.
- PHP/composer tests, coverage and app integration were not run: this continuation changes monitoring configuration/rules only and does not touch application code.
- Sentry `/metrics` and `/_metrics` were not scraped because both returned HTML rather than Prometheus exposition.
- D proposals remain unapplied; changing Redis/PostgreSQL settings or Elasticsearch memory/swap behavior was outside the diagnostics-only scope. Confluence pages were read, but no write was authorized.

## Production changes and rollback

| Host | Production files / change | Backup | Rollback |
|---|---|---|---|
| `.4` Gatekeeper | New `/usr/local/bin/haproxy_exporter` and `/etc/systemd/system/haproxy-exporter.service`; binds `.4:9101`, permits `.10` only. HAProxy itself was not changed. | `/root/backup-JOOXCR-388-20261105/C-haproxy` (verified empty before installation; target paths absent). | Stop/disable only `haproxy-exporter.service`, remove the two new files, then `systemctl daemon-reload`.
| `.10` monitor | New blackbox binary/config/firewall and units; updated `/etc/prometheus/prometheus.yml`; installed rules 11–15. Prometheus reloaded by SIGHUP only. | `/root/backup-JOOXCR-388-20261105/C-blackbox` for the pre-C config and original rules 01–10; `/root/backup-JOOXCR-388-20261105/C2-awesome-rules/{11-redis.yml,12-mongodb.yml,15-blackbox.yml}` for the pre-follow-up versions of those C rules. | To undo only the follow-up rule expansion, restore 11, 12 and 15 from `C2-awesome-rules`, validate, and HUP Prometheus. To fully undo C, restore the pre-C config and rules 01–10 from `C-blackbox`, remove C-added rules 11–15, validate and HUP Prometheus. Remove only the new blackbox units/files and dedicated nftables table; stop/disable the HAProxy exporter unit and remove its binary/unit.
| `.10` monitor | Alertmanager Telegram route/config/templates and secrets; B6 used a temporary config window, then restored the exact saved config. | `/root/backup-JOOXCR-388-20261105/alertmanager.yml`; B6 exact-current backup `/root/backup-JOOXCR-388-20261105/B6-alertmanager-current.yml`. | Restore the saved `alertmanager.yml` and send Alertmanager SIGHUP; remove only A-created template/secret files after the previous config is loaded.
| `.15` Elasticsearch | New drop-in `/etc/systemd/system/elasticsearch-exporter.service.d/jooxcr388-monitoring.conf`; added `view_index_metadata` to read-only role `exporter_monitor`; exporter unit only was restarted. Elasticsearch itself was not restarted. | `/root/backup-JOOXCR-388-20261105/C-elasticsearch/elasticsearch-exporter.service` and `exporter_monitor-role.json`. | Restore the prior role JSON via the Elasticsearch security API; remove the new drop-in, run `systemctl daemon-reload`, and restart only `elasticsearch-exporter.service` if reverting its source filter.
| `.12`, `.14`, `.15` D diagnostics | No database or main-service configuration changes. | Not applicable. | Not applicable.

Earlier A and B1–B5 backups and rollbacks are detailed in `/home/joos/Workspaces/.tmp/codex/log-388-steps.md`; these steps were not re-applied.

### A and B1–B5 backup inventory

These are previously completed `.10` changes; this continuation did not apply them again. The recorded backup inventory is:

- **A:** `/root/backup-JOOXCR-388-20261105/alertmanager.yml` and `/root/backup-JOOXCR-388-20261105/rules/` (original pre-A rule files 01–09). Alertmanager routing can be rolled back independently by restoring its file, validating it, sending SIGHUP, then removing A-created template/secret files. Restoring the original rule files rolls the Prometheus rules back to pre-A and also undoes later B edits on overlapping files. The recorded inventory does not contain a complete post-A snapshot for an isolated A-only rollback of the full rule set; see the B3 limitation below.
- **B1:** `/root/backup-JOOXCR-388-20261105/B1/03-mariadb.yml`; restore to `/etc/prometheus/rules/03-mariadb.yml`, validate and send Prometheus SIGHUP.
- **B2:** `/root/backup-JOOXCR-388-20261105/B2/09-xcrawler.yml`; restore to `/etc/prometheus/rules/09-xcrawler.yml`, validate and send Prometheus SIGHUP.
- **B3/B4:** `/root/backup-JOOXCR-388-20261105/B4/02-node-exporter.yml` is present; no isolated B3 backup directory/file was present in the inspected backup inventory. The original `/root/backup-JOOXCR-388-20261105/rules/02-node-exporter.yml` is the pre-A baseline and can restore the file only by also undoing any A-era edits to it. Therefore an isolated B3 rollback that preserves A cannot be performed from the recorded backups. B4 can be rolled back to its B4 snapshot after validating and sending Prometheus SIGHUP.
- **B5:** `/root/backup-JOOXCR-388-20261105/B5/prometheus.yml` records the config snapshot; new rule `/etc/prometheus/rules/10-rabbitmq.yml` had no pre-existing file to back up. To undo B5, remove only `10-rabbitmq.yml`; restore the config snapshot only if the live config differs, then validate and send Prometheus SIGHUP.

Jira subtasks JOOXCR-387 through JOOXCR-391 were created/read back under JOOXCR-373. Commit links and scope notes were added and read back on each subtask. Relevant commits: [A](https://github.com/jooservices/javmeta/commit/b95b7c0bec2f218f480f7bea0cee5a132a961151); [B1](https://github.com/jooservices/javmeta/commit/12f8de18664a1d12e0d1413f7e02bf551602fe4d), [B2](https://github.com/jooservices/javmeta/commit/80e1fc8eaaefc403b6223e810aa5d171629a659a), [B3](https://github.com/jooservices/javmeta/commit/1eb5d478685bf60d27acc13dd05bac0805dad7cc), [B5](https://github.com/jooservices/javmeta/commit/945695699ed8f595f52df3036a766416ebbbc4e3); [C](https://github.com/jooservices/javmeta/commit/8da460b22d5e61441984e02ddf574bf3c7878ab8); [this continuation/report](https://github.com/jooservices/javmeta/commit/d0c180e94e425880337a498e030d555225cf25fe). No separate B4 commit was recorded in the task worktree history.

Historical findings are stored in verified Workspaces memory entry `e315e72f-fecc-47f9-8216-11841420c0a4` (JOOservices / JOOXCR); live state must be checked again before operational changes.

## Confluence update draft — not published

Current pages read: Monitoring VM v2, Prometheus v1, Alertmanager v1, Infrastructure Inventory v50, Network & Operations v5, Gatekeeper operations v5, HAProxy v1, Elasticsearch VM v3, PostgreSQL VM v2 and Redis VM v3. Add dated, scoped observations to the affected pages only after owner authorization:

- **Monitoring VM / Prometheus:** “Observed 2026-11-05 17:33 UTC: Prometheus reload succeeded; 37/37 targets and 169/169 rules were healthy. Blackbox exporter listens on `.10:9115`, restricted to Prometheus; HAProxy `.4:9101`, Elasticsearch `.15:9114`, and OpenObserve `/metrics` are scraped. Three HAProxy backends remain DOWN.”
- **Alertmanager:** “Observed 2026-11-05 17:33 UTC: live Telegram routes and daily info digest validated; four labelled test notification paths delivered; final Telegram failure counters were zero. No test alert remained active.”
- **Gatekeeper / HAProxy:** “Observed 2026-11-05 17:33 UTC: new HAProxy exporter on `.4:9101` is restricted to `.10`; `aicc-web`, `flickr-web` and `jav-web` report L4 timeout.”
- **Elasticsearch / PostgreSQL / Redis VM pages:** append the dated D measurements and label the suggested memory, connection-lifecycle and `maxmemory` changes **Proposed**, not implemented. No production setting was changed during diagnostics.

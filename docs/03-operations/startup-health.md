# Startup health gate

`php artisan health:startup [--role=web|worker|scheduler] [--wait=SECONDS]`
checks the services a process needs before it serves requests or starts work. It
prints a redacted service table and returns 0 when every enabled primary check
passes, or 1 when a primary check fails. The default wait comes from
`health.startup.wait_seconds` (60 seconds); retries use the configured backoff.
Each network request or Redis command has a maximum two-second timeout. A
probe that checks several configured connections or endpoints can take longer
than two seconds in total.

## Policy and fallback

PostgreSQL (`SELECT 1` and `SHOW timezone` must report UTC; PostgreSQL's
`Etc/UTC` alias is accepted), Redis (PING and a
prefixed write/read/delete on each configured queue, cache, session and Horizon
connection), and MongoDB (ping plus a fresh read of the settings store) are
always primary. Elasticsearch is primary on the web role and secondary on
workers and the scheduler by default. The Cài đặt page can add Elasticsearch to
another role; it cannot remove the three bootstrap primaries. Every other
configured integration is secondary.

When MongoDB is unavailable, health cannot trust database policy. Code defaults
therefore keep the gate enabled and require PostgreSQL, Redis and MongoDB. A
failed settings-store refresh has the same fallback. Once the store is reachable,
`health.startup.*` settings apply. Disabling the gate is itself a database
setting and takes effect only after the store has been read. A secondary failure
prints `warn`, writes a warning log and emits dependency telemetry when
OpenObserve is available; it never blocks startup.

## Entry points

- Long-running and batch Artisan commands are checked at `CommandStarting`.
  Diagnostic commands in `health.startup.command_allowlist` are exempt by
  default; `command_denylist` overrides the allowlist. A blocked command exits
  non-zero with the failed primary names and emits `startup_blocked` when
  telemetry is available. Health checks are not run for each queued job.
- Web requests use a shared APCu/file snapshot before sessions and auth. Its
  TTL is `health.startup.snapshot_ttl_seconds`; a red primary snapshot returns
  503 with `Retry-After`. `/api/health` and `/api/health/live` bypass this
  middleware so operators can see dependency details or process liveness.
- The native Horizon, scheduler and embedder systemd units run
  `health:startup` in `ExecStartPre`, using the database-configured wait with a
  bounded 660-second start timeout and restart-on-failure policy.
- A clean `horizon:terminate` or `schedule:work` exit does not trigger
  `Restart=on-failure`; use `systemctl restart <unit-prefix>-horizon` or
  `systemctl restart <unit-prefix>-scheduler` for maintenance reloads.
- The installer runs the health command against the staged release before it
  stops existing units or replaces `current`. A failed check leaves the active
  release and units untouched.

`staging:readiness` is unchanged. It remains the post-start operational check,
not the dependency gate.

## Database settings

All tuning is under the **Sức khỏe khi khởi động** group on Cài đặt. The
registry in `config/javmeta_settings.php` defines type, limits, Vietnamese
labels and descriptions. This includes the enable switch, per-probe timeout,
wait deadline, retry delay, web snapshot TTL, per-role Elasticsearch policy,
command allow/deny patterns and optional browser gateway URLs. Defaults live in
`config/javmeta_health.php` and contain no environment reads. See the generated
[Settings reference](../user-guide/settings-reference.md) and [Settings
operations](settings.md).

Check the report on the target release before starting units:

```sh
php artisan health:startup --role=web --wait=60
php artisan health:startup --role=worker --wait=0
```

Messages never include credentials, tokens, DSNs or response bodies.

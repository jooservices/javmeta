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
  `health:startup` in `ExecStartPre`, using the database-configured probe
  timeout, wait, retry delay and dependency lists. `TimeoutStartSec=660` is a
  systemd bootstrap ceiling; it does not replace the database settings.
  `Restart=on-failure` retries after a fixed `RestartSec=15s`. The unit sets
  `StartLimitIntervalSec=0`, so a brief outage cannot exhaust systemd's default
  start-rate limit and leave the service stopped. `StartLimitBurst=5` remains a
  bootstrap guard if the interval policy is changed later.
- The health gate is a start hook only. `systemctl stop` does not run it and a
  manual stop is not automatically undone. `systemctl restart` stops normally,
  then applies the gate to the new start; a failed check leaves the unit stopped
  until its scheduled retry succeeds.
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

## Safe systemd verification

Run this procedure only on a disposable test instance with its own application
configuration and database. It uses a throwaway unit under `/run`; it never
stops or changes a production dependency or application unit.

1. Use only the test instance's own database and endpoints. For the first run,
   point its PostgreSQL, Redis and MongoDB connections at unused loopback ports,
   for example `127.0.0.1:1`, `127.0.0.1:2` and `127.0.0.1:3`. The throwaway
   unit below passes `--wait=0` only to make this manual failure check quick;
   production units omit that override and use the database setting.
2. Create a throwaway service using the test release's PHP path, service user
   and current directory. Set these values to that test instance's paths, then
   write the throwaway unit:

   ```sh
   TEST_SERVICE_USER='javmeta-test'
   TEST_TARGET='/srv/javmeta-test'
   TEST_PHP='/usr/bin/php8.5'
   TEST_UNIT='javmeta-startup-health-check.service'
   sudo tee "/run/systemd/system/$TEST_UNIT" >/dev/null <<UNIT
   [Unit]
   Description=Throwaway JavMeta startup health check
   StartLimitIntervalSec=0

   [Service]
   Type=simple
   User=$TEST_SERVICE_USER
   WorkingDirectory=$TEST_TARGET/current
   ExecStartPre=$TEST_PHP artisan health:startup --role=worker --wait=0
   ExecStart=/usr/bin/sleep infinity
   Restart=no
   UNIT
   sudo systemctl daemon-reload
   if sudo systemctl start "$TEST_UNIT"; then
     echo 'Expected primary dependency failure.' >&2
     exit 1
   fi
   sudo journalctl -u "$TEST_UNIT" -n 20 --no-pager
   timeout 10s sudo systemctl stop "$TEST_UNIT"
   ```

   The journal must report a primary dependency failure. The stop command must
   return promptly even after the failed start.
3. Restore the test instance's primary endpoints to its own reachable test
   services. In its test database, set `search.enabled=true`,
   `search.embedder_enabled=true`, and `search.embedder_url` to
   `http://127.0.0.1:1` through the test instance's Cài đặt page. Then run:

   ```sh
   sudo systemctl start "$TEST_UNIT"
   sudo systemctl is-active --quiet "$TEST_UNIT"
   sudo journalctl -u "$TEST_UNIT" -n 20 --no-pager
   sudo systemctl restart "$TEST_UNIT"
   sudo systemctl stop "$TEST_UNIT"
   ```

   The unit must become active, and the journal must show the embedder warning.
4. Remove only the throwaway unit from `/run/systemd/system`, then run
   `sudo systemctl daemon-reload` again:

   ```sh
   sudo rm -f -- "/run/systemd/system/$TEST_UNIT"
   sudo systemctl daemon-reload
   ```

   Do not run this procedure against the production database, addresses, or
   unit names.

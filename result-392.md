# JOOXCR-392 result

## Done

- Released idle PostgreSQL PDOs after queue jobs and scheduled tasks. Connections
  with active transactions are kept open; cleanup errors are logged without
  stopping the worker.
- Added a fleet budget using the configured role limit and reserve. The default
  is 120 role slots minus a 30-slot non-worker reserve, for a 90-slot Horizon
  ceiling. Horizon falls back to its code defaults at boot when saved settings
  exceed the budget, and the reconcile loop refuses over-budget scaling.
- Added Prometheus gauges at `GET /metrics`, matching OpenObserve metric points,
  and an alert that fires when demand reaches the configured warning threshold
  (75% of the role limit by default).
- Inventory: 17 supervisors total 22 processes per node (`6×1 + 5×2 + 5×1 +
  1×1`). The configured fleet is three Horizon nodes (one primary, two workers),
  so the default theoretical maximum is 66 worker PostgreSQL slots. The
  unconstrained settings ceiling would be `17×32×3 = 1,632` slots. The audit's
  107 role sessions exceed the default estimate by 41; the newer evidence's
  nine-slot headroom implies 111 role sessions, 45 above the estimate. The
  snapshots are not synchronized and do not identify individual owners.
- The repo config has one PostgreSQL connection (`pgsql`), no `pgsql_read` alias,
  and no read/write pools. The budget counts any future named PostgreSQL
  connection and an additional slot for a read/write pool. There is no Reverb
  service/configuration in this repo; the primary runs one scheduler and up to
  12 PHP-FPM children, and the reconcile timer is short-lived.
- No PostgreSQL server setting or production service was changed.

## Files

- Application: worker budget/layout/reconcile services; idle connection listener
  and registration; Prometheus controller/route; metric stream registry and
  OpenObserve publisher; guest route policy; Horizon command output.
- Configuration and deployment: budget env allowlist/defaults, installer
  manifest, `.env` examples, production runbook, and the OpenObserve warning
  alert definition.
- Documentation: PostgreSQL connection inventory and calculation, settings,
  queue operations, deployment, guest mode, and telemetry references.
- Tests: budget refusal/calculation, layout and reconcile guards, `/metrics`,
  OpenObserve metric publication/import, and real PostgreSQL idle-connection and
  active-transaction integration coverage.

## Tests

- `docker/ci/run composer lint` — pass; Pint 960 files, PHPStan no errors,
  PHP-CS-Fixer 947 files with no changes.
- `docker/ci/run composer test:coverage` — 2,307 passed, 1 skipped, 22,068
  assertions; 1,272.28 seconds.
- `docker/ci/run composer coverage:check` — pass at 93.76% (15,173/16,183
  statements; 90% minimum).
- `docker/ci/integration` — 120 tests, 1,382 assertions; pass. The suite's
  Mongo-outage health test is skipped in the normal pass and then run by the
  harness with Mongo stopped; that separate run passed (1 test, 20 assertions).
- Targeted connection cleanup unit coverage — 4 passed, 12 assertions; the
  listener has 100% statement coverage.
- Targeted Horizon reconcile integration — 11 passed, 94 assertions; the
  reconciler has 87.2% statement coverage. The suite also verifies that the
  command reports and refuses over-budget scaling.
- Other measured changed application files: connection budget 100%, metrics
  controller 100%, worker layout 99.1%, and `AppServiceProvider` 94.6% statement
  coverage. PHPUnit intentionally excludes all `app/Console/Commands` from
  coverage; the changed metrics command behavior is asserted in feature tests.
  Route/registry/config declaration files have no executable statements in the
  Clover report and are exercised by route, guest-mode, settings, and metrics
  tests.

## Skipped

- One suite test requires APCu enabled for CLI; this Docker CLI has APCu disabled.
- The normal integration pass skips the Mongo-outage test by design; the
  integration harness reruns it with Mongo stopped, and it passed.
- Production verification/deployment was not run because production is
  read-only for this task.

## Production changes

- Host: none. No production host was accessed or changed.
- File: none. No live file backup was needed.
- Backup path: not applicable.
- Rollback: not applicable. PostgreSQL role limit, `max_connections`, and
  services remain unchanged; no PostgreSQL restart was performed.

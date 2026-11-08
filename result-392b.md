# JOOXCR-392b result

## Done

- Horizon `SupervisorLooped` and `MasterSupervisorLooped` now reuse
  `DisconnectIdlePostgresConnections` so master/supervisor processes release the
  boot-time settings PDO between loops (same transaction safety rule as workers).
- `schedule:work` releases that PDO on `CommandStarting` after settings apply; the
  long-lived parent only spawns `schedule:run` children and no longer keeps an
  idle connection for its lifetime.
- Lazy reconnect verified: after a supervisor-loop release, the next query opens
  a fresh PDO.
- No production host, PostgreSQL role limit, or `max_connections` change.

## Measurement (docker/ci/integration gate)

Simulated boot (`SELECT 1` on `pgsql`, same shape as `SettingsService::apply()`)
then the matching cleanup path. Script: `.tmp/measure-horizon-idle-pg.php`.

| Process type | Idle app PDO before | `pg_stat_activity` idle before | Idle app PDO after | `pg_stat_activity` idle after |
|---|---|---|---|---|
| `horizon:supervisor` (`SupervisorLooped`) | 1 | 1 | 0 | 0 |
| `horizon:master` (`MasterSupervisorLooped`) | 1 | 1 | 0 | 0 |
| `schedule:work` (`CommandStarting`) | 1 | 1 | 0 | 0 |

Expected after the fix: supervisors (and schedule:work) hold **0** idle
connections between loops / after boot cleanup. Before the fix, the RED
integration assertion showed `SupervisorLooped` left the PDO open.

## Files

- `app/Listeners/DisconnectIdlePostgresConnections.php` — Horizon loop and
  `schedule:work` entry points; shared disconnect logic unchanged.
- `app/Providers/AppServiceProvider.php` — register the new event listeners.
- `docs/03-operations/postgres-connections.md` — document Horizon/schedule:work
  release behaviour.
- Tests: unit, feature (boot + event wiring), integration (real Postgres PDO,
  reconnect, schedule:work dispatch).

## Tests

- RED first: `SupervisorLooped` left a live PDO (integration failure before the
  listener wiring).
- `docker/ci/run composer lint` — pass.
- `docker/ci/run composer test` — 2,314 passed, 1 skipped, 22,090 assertions.
- `docker/ci/integration --filter DisconnectIdlePostgresConnectionsTest` — 5
  tests, 31 assertions; pass.
- Listener statement coverage (targeted clover): **100%** (22/22).
- New `Event::listen` lines in `AppServiceProvider` run on every application
  boot in the suite.

## Production changes

- Host: none.
- File: none.
- Backup path: not applicable.
- Rollback: not applicable. Application-only listeners; revert the commit to
  restore prior behaviour.

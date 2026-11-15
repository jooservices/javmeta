# Browser QA stack lifecycle and concurrency

## Current stack

[`compose.qa.yml`](./compose.qa.yml) declares the shared Compose project
`javmeta-qa`. The supported [`qa-up`](./qa-up) and [`qa-down`](./qa-down)
scripts do not assign a run-specific project name, so
invocations on the same Docker engine use the same services, network, and
application. An inherited `COMPOSE_PROJECT_NAME` can override the declared
name, but the scripts do not generate or validate a unique value.

`JAVMETA_QA_PORT` selects the host port (`8099` by default) and the matching
application URL. A different port avoids a host-port bind conflict; it does not
create a separate Compose project or separate backing services. The included
integration configuration also mounts the checkout into the web service, so a
second worktree can cause the shared web service to be recreated with that
worktree's source. Unless `JAVMETA_CI_APP_KEY` is supplied, `qa-up` generates a
new app key on each invocation; simultaneous startup can also race web-service
configuration.

PostgreSQL, MongoDB, Redis, Elasticsearch, and OpenObserve keep their data in
container tmpfs. That data is fresh after the stack is torn down and recreated;
`qa-up` can reuse an already-running stack and does not reset it first. The
external Composer cache volume `javmeta-ci-composer-cache` is shared outside
the project namespace and is not removed by `qa-down`.

## Browser-run state

Each runner process launches a separate browser, and each scenario gets a
fresh browser context. Those contexts do not isolate the application state:
the default `QA_BASE` points to the same app, the default owner account is
`qa@javmeta.test`, and scenarios use the same backing data. The default
password file is `.tmp/qa-password` within a checkout. The default screenshot
directory is `.tmp/qa-shots`; parallel runs from the same checkout can write
the same `FAIL-<scenario>.png` paths. The browser defaults and output paths are
set in [`context.mjs`](../../ui-tests/qa/lib/context.mjs), and fresh scenario
contexts are created in [`runner.mjs`](../../ui-tests/qa/lib/runner.mjs).

## Concurrency assessment

There is no QA lifecycle lock or lock owner in `docker/qa` or `ui-tests/qa`.
`qa-up` starts the fixed project and returns before the browser suite runs.
`qa-down` unconditionally runs `down --volumes --remove-orphans` and does not
check which invocation started the stack. A concurrent teardown can stop
another run and discard its tmpfs data. Different host ports do not prevent
these collisions.

An isolated mock ran two `qa-up` invocations concurrently with host ports
`18101` and `18102`, while replacing `docker`, `curl`, and `mkdir` with local
stubs. Both invocations reached the same Compose file with
`COMPOSE_PROJECT_NAME` unset; their mocked calls overlapped and both requested
the fixed Composer cache volume. No container was started and no service was
contacted. This confirms that the current scripts do not serialize startup;
the fixed Compose name and shared resources make concurrent browser runs
unsupported.

## Ownership and compatible design

Until lifecycle locking or per-run isolation is implemented, run one full
session at a time: `qa-up`, the browser suite, then `qa-down`. The session that
starts the stack owns it and is the only session allowed to stop it. A host-wide
lock must be shared by every worktree using the same Docker engine; a lock
inside a worktree would not coordinate other checkouts. A lifecycle wrapper
should acquire the lock before startup and retain it through browser execution
and teardown, so the short `qa-up` process does not release ownership too
early.

If parallel browser runs are required, give each run a unique Compose project
name, host port, application URL, QA account and password file, screenshot
directory, and backing-data namespace. Keep the external Composer cache
explicitly shared only if concurrent cache access is supported; otherwise give
each project its own cache volume. A unique port alone is insufficient.

The PHP E2E runner's worktree database and per-run prefixes are a separate
harness; they do not isolate this browser QA Compose project. See
[`ui-tests/qa/README.md`](../../ui-tests/qa/README.md) for runner commands and
[`docs/03-operations/e2e.md`](../../docs/03-operations/e2e.md) for the PHP E2E
scope.

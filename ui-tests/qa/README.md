# Browser E2E: steps, scenarios, suites

Runs against the real stack (no mocks): `docker/qa` starts Postgres, Mongo, Redis, Elasticsearch, OpenObserve and the app on
`http://localhost:${JAVMETA_QA_PORT:-8099}` with the sample dataset and an owner account. Set `JAVMETA_QA_PORT` (and matching
`QA_BASE`) when the default host port is busy; do not use `javmeta-qa2` / `:8098`.

```bash
JAVMETA_QA_PORT=18099 docker/qa/qa-up          # omit JAVMETA_QA_PORT to keep the default :8099
cd ui-tests/qa && npm install
QA_BASE=http://localhost:18099 node run.mjs    # the main suite; exit code 1 when anything fails
node --test lib/runner.test.mjs                # the runner itself, with fake steps
docker/qa/qa-down                              # drops the throw-away data
```

## How it is built

- **Step** (`steps/*.mjs`): one reusable action, e.g. `login()`, `openView('#/settings')`, `openTab('crawl')`, `toggleFirstSwitch()`,
  `submitNodeForm()`. Defined once with `defineStep(name, async (ctx, ...args) => ...)`.
- **Scenario** (`scenarios/index.mjs`): an ordered list of steps. It never re-implements a step; needing login means listing `login()`.
- **Suite** (`suites/*.mjs`): the scenarios that run together. `run.mjs <suite>` runs one.
- **Runner** (`lib/runner.mjs`): runs steps in order, stops a scenario at its first failure, saves `FAIL-<scenario>.png`,
  carries on with the next scenario, prints a result per step.

The same words as the PHP E2E in `tests/E2E` (Step, Scenario, Context).

## What the shared checks mean

`expectViewHealthy` fails when a view is empty, shows an error panel or a not-found page, scrolls sideways, or raised a console
error or a failed request. `expectTabShowsControls` fails on a hidden pane, a wrong active tab, no sections, sideways scroll or a
native checkbox outside the shared `.switch`.

Environment: `QA_BASE` (default `http://localhost:8099`; set it to the same host port as `JAVMETA_QA_PORT`), `QA_EMAIL`,
`QA_PASSWORD_FILE` (default `.tmp/qa-password`), `QA_SHOTS`, `CHROME_PATH` (default: the local Playwright Chromium).
Login is throttled to 6 attempts a minute, as in production.

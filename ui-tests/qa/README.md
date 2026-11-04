# Browser QA walk

Starts from the real stack, not mocks:

```bash
docker/qa/qa-up                      # Postgres, Mongo, Redis, Elasticsearch, OpenObserve + the app on :8099 with the sample dataset
cd ui-tests/qa && npm install
node walk.mjs http://localhost:8099 qa@javmeta.test ../../.tmp/qa-password ../../.tmp/qa-shots
docker/qa/qa-down                    # drops the throw-away data
```

`walk.mjs` signs in as the owner, opens every view at desktop (1440 px) and phone (390 px) width and reports, per view:
console errors, failed or 4xx/5xx requests, horizontal overflow, an empty main area, unlabeled buttons and tiny touch
targets. It saves a screenshot per view and width plus `report.json`.

Chromium comes from the local Playwright cache (`~/.cache/ms-playwright/chromium-*`); set `CHROME_PATH` to use another binary.

## Interaction checks

`settings.mjs` opens every Settings tab, toggles a switch, saves its group and reloads to prove the value persisted:

```bash
node settings.mjs http://localhost:8099
```

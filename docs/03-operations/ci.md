# CI

GitHub Actions on GitHub-hosted runners. CI only: nothing is deployed and no
image is pushed. Deploy stays manual (`deploy/*.sh`).

## Workflows

| Workflow | Trigger | Jobs |
|---|---|---|
| `ci.yml` | push / PR to `develop`, manual | **Lint and test**: composer validate, `composer lint`, `composer test:coverage`, `composer coverage:check` (≥ 85%), coverage artifact · **Integration**: `docker/ci/integration` against real Postgres, Mongo, Elasticsearch and OpenObserve · **Secrets scan**: gitleaks with `.gitleaks.toml` · **Build app image**: `Dockerfile.production`, no push |
| `embedder-image.yml` | changes under `embedder/` | build `embedder/Dockerfile.production`, no push |
| `workflow-audit.yml` | changes under `.github/`, weekly | actionlint (pinned image), zizmor (action uploads SARIF to code scanning) |
| `commitlint.yml` | pull requests | Conventional Commits, sentence-case subject (same rule as `captainhook.json`) |

All actions are pinned by commit SHA.

## Reproduce locally (same image as CI)

The gate runs inside `docker/ci/Dockerfile` (PHP 8.5, ext-mongodb, intl,
pcntl, pdo_pgsql, pcov, Composer). The host PHP is not used, so host
extension versions do not matter.

```bash
docker/ci/run composer install --no-interaction --prefer-dist
docker/ci/run composer validate --strict
docker/ci/run composer lint
docker/ci/run composer test:coverage
docker/ci/run composer coverage:check
docker build --file Dockerfile.production .
```

Workflow checks:

```bash
docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest
docker run --rm -v "$PWD:/repo" -w /repo ghcr.io/zizmorcore/zizmor:latest --offline .github/workflows
```

Tests use sqlite in-memory and an array cache (`phpunit.xml`), so no Postgres,
Redis or Mongo service is needed.

## Test layers

| Layer | Where | What it proves |
|---|---|---|
| Unit / Feature | `tests/Unit`, `tests/Feature` | javmeta logic on sqlite in-memory; crawlerx replaced by `FakeCrawlerxClient` |
| crawlerx contract | `tests/Feature/Contract` | the **real** crawlerx stack parses every live-captured fixture (`tests/Fixtures/crawlerx`) and the javmeta jobs store the result; generated failures (404, 410, 429, 5xx, network, empty, Cloudflare challenge, drifted page) end in the expected row state. Only the HTTP transport is faked (`ClientBuilder::fake()`) |
| Integration | `tests/Integration` (`phpunit.integration.xml`) | real **Postgres** (all migrations apply, roll back, re-apply), **Mongo** archive, **Elasticsearch** documents and **OpenObserve** ingestion. Every movie and performer fixture goes through crawlerx → job → Postgres (`movies`/`movie_sources`, `performers`/`performer_sources` with the Mongo id) → Mongo → ES; a recrawl does not duplicate the movie, source link, archive or ES document (observations stay append-only by design); a crawl's `crawl_fetch` record is queryable in OpenObserve. Only the embedder is faked |

The failure expectations pin the crawlerx 1.2 contract (every fetch failure
is `blocked` or `challenge`, no retry). They change on purpose when javmeta
moves to crawlerx 1.3.

After upgrading crawlerx, refresh the fixtures and rerun the suite:

```bash
docker/ci/run php tools/sync-crawlerx-fixtures.php
docker/ci/run composer test
```

Run the integration suite (starts the services in Docker, tmpfs storage,
removes them afterwards):

```bash
docker/ci/integration                      # or: make test-integration
docker/ci/integration --filter Performer   # extra PHPUnit arguments
```

The CI services use the same versions as the Workspaces shared Docker (PostgreSQL 18.6,
MongoDB 9.0.4, Elasticsearch 9.5.5, OpenObserve 1.0.4, Redis 8.2.10). For local development and QA,
run the same suite against the shared services instead of starting a second stack:

```bash
scripts/docker/shared provision-javmeta-test     # once, from the Workspaces root: creates the JAVMETA_IT_* namespaces
docker/ci/integration-shared                     # whole suite against the shared services
docker/ci/integration-shared --filter Performer  # extra PHPUnit arguments
```

The runner reads `JAVMETA_IT_*` from the private Workspaces `.env` into a temporary 0600 file and
never prints values. The test accounts are least-privilege (Mongo `readWrite`, Elasticsearch role on
`javmeta_it_*`, an OpenObserve organization of their own), so the suite resets Mongo by dropping
collections rather than the database. The Mongo outage scenario stays CI-only because it stops the
service. Elasticsearch 9 leaves `dense_vector` fields out of `_source`; tests that read vectors
back pass `_source_exclude_vectors=false`.

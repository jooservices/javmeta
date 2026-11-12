# CI

GitHub Actions on GitHub-hosted runners. CI only: nothing is deployed and no
image is pushed. Deploy stays manual (`deploy/*.sh`).

## Workflows

| Workflow | Trigger | Jobs |
|---|---|---|
| `ci.yml` | push / PR to `develop`, manual | **Lint and test**: composer validate, `composer lint`, `composer test:coverage`, `composer coverage:check` (≥ 90%), coverage artifact · **Integration**: `docker/ci/integration` against real Postgres, Mongo, Elasticsearch and OpenObserve · **Secrets scan**: gitleaks with `.gitleaks.toml` · **Build app image**: `Dockerfile.production`, no push |
| `embedder-image.yml` | changes under `embedder/` | build `embedder/Dockerfile.production`, no push |
| `workflow-audit.yml` | changes under `.github/`, weekly | actionlint (pinned image), zizmor (action uploads SARIF to code scanning) |
| `commitlint.yml` | pull requests | Conventional Commit headers, sentence-case subject |

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

## Current per-file unit and integration coverage

The **95.28%** overall result recorded in [JOOXCR-374](https://jooservices.atlassian.net/browse/JOOXCR-374)
on 2026-11-05 covered 15,076 of 15,823 statements on branch
`JOOXCR-373-readiness`, with no app files below 85%. It is historical and does
not describe the current `develop` head.

On 2026-11-12, `origin/develop` at
`381336f0a2d0528ea006693dca8f69e114cb30ef` was measured. The current combined
unit and integration result is **unknown**: `docker/ci/run composer test:coverage`
generated the unit and feature Clover report, but the integration report
`build/integration.xml` is missing. The merged command fails with
`Coverage file build/integration.xml not found.` Do not infer a pass or publish
combined per-file percentages or missing-statement counts until both reports
have independently passed the validation below.

Reproduce the current inputs and validate each report before merging:

```bash
docker/ci/run composer test:coverage

for report in build/coverage.xml build/integration.xml; do
  test -s "$report" || { echo "Missing or empty report: $report" >&2; exit 1; }
  docker/ci/run php -r '
    $path = $argv[1];
    $xml = simplexml_load_file($path);
    $statements = 0;
    $root = rtrim(str_replace("\\", "/", getcwd() ?: "."), "/");
    if ($xml !== false && $xml->getName() === "coverage") {
        foreach ($xml->xpath("//file") ?: [] as $file) {
            $name = str_replace("\\", "/", (string) $file["name"]);
            $isAppFile = str_starts_with($name, "app/")
                || str_starts_with($name, "/app/app/")
                || str_starts_with($name, $root . "/app/");
            if (! $isAppFile) {
                continue;
            }
            foreach ($file->line as $line) {
                if ((string) $line["type"] === "stmt") {
                    $statements++;
                }
            }
        }
    }
    if ($xml === false || $xml->getName() !== "coverage" || $statements === 0) {
        fwrite(STDERR, "Not a usable Clover app-coverage report: {$path}\n");
        exit(1);
    }
    printf("%s: %d app statements\n", $path, $statements);
  ' "$report" || exit 1
done

docker/ci/run composer coverage:per-file
```

The check requires a nonempty XML file with a Clover `<coverage>` root and at
least one executable `stmt` line for an application file in each report. It
recognizes repository-relative `app/...`, container `/app/app/...` and current
worktree `<repo-root>/app/...` paths, and ignores other `/app/...` paths. Confirm the
reports came from their respective unit/feature and integration suites at the
same pinned commit. `coverage:per-file` in `composer.json` requires both
`build/coverage.xml` and `build/integration.xml`;
`tools/quality/coverage-per-file.php` checks only that each file exists and
parses as XML, then merges statement lines it finds under `app/`. It does not
validate the Clover root or ensure either input contains measurable app data:
`<coverage/>` is accepted, two empty reports produce a 100% result across zero
files, and an empty integration report with populated unit coverage produces a
unit-only result. A valid XML document with no relevant app statements has the
same issue. Treat any missing, empty, malformed, non-Clover, or irrelevant
input as **unknown**, regardless of the merge command's exit code or output.
Do not report percentages or missing-statement counts unless both reports
pass validation and the merged command completes.

The current CI integration job runs `docker/ci/integration` without generating
an integration Clover file. The local shared integration runner uses the
shared `jvmeta_it` PostgreSQL database for every worktree ID, so it cannot
produce a report from the required per-worktree database. No integration run
was started against that shared database.

Bounded follow-up batches:

1. Provide an integration coverage runner that writes Clover from an isolated
   per-worktree database and test identity; keep missing and empty reports as
   explicit failures.
2. Rerun both reports on a pinned current `develop` commit and merge them with
   `coverage:per-file`. Group any measured failures into batches of at most
   five files by feature area, including each file's missing statement count.
   Keep the 85% per-file and 90% overall thresholds unchanged.

## PR size policy

`tools/quality/pr-size-check` enforces the PR size policy: it counts the files
changed between the merge base of the base ref and `HEAD`, warns at 30 changed
files and fails at 100. Above the fail threshold it only passes with an
explicit, documented exception passed as `--reason`, whose text must start with
`mass-rename:` or `generated files:` (ordinary feature work is not an exception);
there is no silent bypass. `--warn` and `--fail` may be lowered for a stricter
local check but never raised above 30 and 100.

```bash
tools/quality/pr-size-check --base origin/develop        # local run (also works on a worktree)
tools/quality/pr-size-check --reason 'mass-rename: split App namespace'
```

The base defaults to `origin/develop`, falling back to `origin/master`;
`PR_SIZE_BASE`, `PR_SIZE_WARN`, `PR_SIZE_FAIL` and `PR_SIZE_REASON` override
the defaults. The check compares the merge base, so it is correct on a branch
that has drifted behind its base. CI invocation of this check is wired in a
separate change.

## Test stability gate calibration

The "Lint and test" job runs the full suite three times and compares each run with a baseline kept in
`.github/workflows/ci.yml` (`baseline_seconds`, `warning_seconds` = baseline + 30%, `maximum_seconds` = 2x baseline;
a run above the maximum fails the job, a run above the warning value only warns). The gate exists to catch a 2x slowdown, not to
chase runner noise.

Current calibration (2026-11-02): baseline 150 s, warning 195 s, maximum 300 s. The previous baseline (95 s, maximum 190 s)
dated from a much smaller suite. With 1,265 to 1,357 tests every run on GitHub-hosted runners took 190 to 235 s (PRs 181 to 185 and develop,
2026-11-01 and 2026-11-02; no individual test was slow), so correct pull requests failed at random and needed several reruns.

Recalibrate in a dedicated pull request when the number of tests changes by more than 20% or when the median of five consecutive
runs on develop moves by more than 20%. Record the measured runs in the pull request. Never raise the maximum to hide a regression
that a single new test or change caused; find the slow test first.

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
MongoDB 9.0.4, Elasticsearch 9.5.5, OpenObserve 1.0.4, Redis 8.2.10).

## Local development and QA (Workspaces shared services)

On a developer machine nothing is built or started per run. `docker/ci/run` is a `docker exec` into the
single long-lived PHP 8.5 runtime (`scripts/docker/runtime php`); on GitHub Actions (or with
`JAVMETA_CI_DOCKER=1`) it still builds and runs the isolated `javmeta-ci` image. Backing services are the
shared ones, with the project databases created by `provision-project` (one root account, no project users):

```bash
scripts/docker/shared provision-project javmeta --postgres --mongodb --redis --db <id>   # once; <id> = 1-12 lowercase letters/digits
docker/ci/integration-shared                          # whole suite on javmeta_test
JAVMETA_MODE=wt:<id> docker/ci/integration-shared    # on this worktree's database javmeta_wt<id>
docker/ci/integration-shared --filter Performer       # extra PHPUnit arguments
```

`docker/dev/shared-run --mode test|wt:<id> -- <command>` generates the environment with
`scripts/docker/shared env-project javmeta` (which runs `check-project` and refuses another project's
database), derives the Elasticsearch indexes, OpenObserve stream and Redis prefix from the same name, and
executes the command in the runtime. No credential is written to a tracked file, printed or left on disk.
Live mode is never accepted. The Mongo outage scenario stays CI-only because it stops the service.
Elasticsearch 9 leaves `dense_vector` fields out of `_source`; tests that read vectors back pass
`_source_exclude_vectors=false`.

## Local Git convention checks

`captainhook.json` runs the shared checker for every commit message and before
every push. The push hook supplies Git's actual ref updates, so the checker
validates every outgoing commit range, including merge commits. Existing refs
use the remote's previous object ID; new refs use the local remote default
branch, falling back to `origin/develop`. The checks use local Git objects and
do not fetch.

The checker enforces the allowed Conventional Commit types, optional scope and
breaking marker, uppercase subject, commit header length, and footer trailers.
It applies Git's comment cleanup before inspecting the whole message, so editor
comments cannot hide prohibited AI or coauthor attribution such as
`Co-Authored-By` and `Generated with`. A project ticket trailer such as
`Ticket: T16` is allowed.

Create PRs through the local wrapper. It requires and checks an explicit title
before invoking `gh pr create`:

```bash
bash tools/quality/create-pr.sh --title 'feat(ui): Add a feature' --base develop
```

Merge commit headers are allowed because Git uses its own merge subject format;
their messages still pass through the prohibited attribution checks. For a
manual pre-check, validate a PR title and every commit in a local range:

```bash
bash tools/quality/check-git-conventions.sh title 'feat(ui): Add a feature'
bash tools/quality/check-git-conventions.sh commits origin/develop..HEAD
bash tests/Shell/git-conventions.test.sh
```

These commands use only the supplied title, commit message file or local Git
objects. They do not call GitHub or fetch a branch.

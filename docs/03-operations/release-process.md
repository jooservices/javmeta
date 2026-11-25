# Release process

Owner decision 2026-11-08: the project is stable and in production, so production runs **tagged releases of `master`** and every release is published as a **GitHub Release**. The first release is `v1.0.0`.

## Branches

| Branch | Purpose | Who writes to it |
|---|---|---|
| `develop` | Integration. Every change arrives by pull request after all checks pass | pull requests only |
| `master` | Production. Protected: pull request required, all conversations resolved, administrators included, no force push, no deletion. Receives only release and hotfix merges | release pull requests only |
| `release/<x.y.z>` | Optional stabilisation branch cut from `develop` for one release, so `develop` keeps moving | the releaser |
| `hotfix/<x.y.z>` | Urgent fix cut from `master` | the releaser |

Day to day: branch from `develop`, make the change, pass every gate (below), open a pull request into `develop`, merge with a merge commit when everything is green.

## Tags

- Only `vX.Y.Z` (release) and `vX.Y.Z-rc.N` (release candidate, published as a pre-release) are valid; the Release workflow rejects any other tag name (`v1.2.3backup`, build metadata, ...).
- Tags are annotated, created on the merge commit of the release pull request on `master`, and are **never moved or deleted**. A ruleset on `v*` tags blocks updates and deletions, and the Release workflow refuses to publish when the tag no longer points to the commit it verified.
- The GitHub Release body is the `CHANGELOG.md` section of the version (so migration notes and their rollback are always included) followed by the generated notes. A tag without a filled changelog section is not published.

## Release flow (the releaser is Claude, the owner does not release)

1. **Prepare.** All intended pull requests are merged into `develop`. Cut `release/<x.y.z>` from `develop` (or use `develop` itself when nothing else is waiting). In the release branch rename the `[Unreleased]` heading of `CHANGELOG.md` to `## [x.y.z] - <date>` and complete it (migrations and their rollback included). The date is set here, before any tag.
2. **Release pull request** `release/<x.y.z>` (or `develop`) -> `master`, title `chore(release): Release <x.y.z>`. Merge commit, never squash.
3. **Green gate (all required, no exceptions).** Every CI job is green: Lint and test, Integration, E2E journeys, Secrets scan, Build app image, commit message and pull request title validation (GitGuardian reports neutral by design). Zero unresolved review threads. Every comment of every reviewer, AI or bot (CodeRabbit, GitGuardian, Qodo, Copilot, ...) is read and either fixed or answered with the reason; no review requests changes. The releaser has also run all steps of `.github/workflows/ci.yml` locally and checked the diff and the tests. Anything not green means no release.
4. **Merge** the release pull request into `master`, then **wait until the push CI of the merge commit on `master` is green** (the same required jobs run for pushes on `master`). The Release workflow refuses a tag whose commit is not green yet; if a tag was pushed too early, re-run the failed workflow after the checks pass (`gh run rerun`); the tag is not recreated.
5. **Release candidate.** Tag the merge commit `v<x.y.z>-rc.1` and push it. The Release workflow checks the tag name, that the tag is on `master`, that the required checks are green on that commit and that the release pull request passed the commit message and title validations, then publishes a pre-release.
6. **Canary.** Capture the production baseline (Sentry groups, Prometheus alerts, `/api/health`, PostgreSQL connections, release running on each node). Preview, then apply the candidate on the primary node `.20` only: `deploy/production.sh --ref v<x.y.z>-rc.1 --start-scheduler` (preview first, then the same command with `--apply`). `--start-scheduler` is required on the primary: without it the installer stops the scheduler and does not start it again. Verify afterwards that the scheduler and Horizon run. Watch at least 10 minutes: Sentry new groups, error rate, alerts, PostgreSQL and Redis connections, Horizon workers, `/app`, `/api/health`, `/ui-api/status/summary`.
7. **Final tag.** If the canary is healthy, tag the SAME commit `v<x.y.z>` and push it (the workflow creates the GitHub Release).
8. **Roll out** `.21` and `.22` with `deploy/production.sh --ref v<x.y.z>` (worker role, no `--start-scheduler`). Verify all three nodes run the tagged commit: the installer writes the full commit SHA to `SENTRY_RELEASE` in `/var/www/xcrawler/current/.env`; compare it on every node with `git rev-parse v<x.y.z>^{commit}`.
9. **Merge back.** Open a pull request `master` -> `develop` (merge commit) so `develop` contains the release merge commit; then delete `release/<x.y.z>`.
10. **Report** to the owner (Telegram at start, after the canary and at the end), update the Jira tickets and resolve the Sentry issues the release fixed (next section).

If the canary fails: roll back `.20` by deploying the previous tag (`deploy/production.sh --ref v<previous> --start-scheduler`), fix on `develop`, then **merge the fix into `master` through a new release pull request with the full green gate**, and only then tag `-rc.2` on the new merge commit. A tag on `develop` is rejected by the workflow. A published tag that turns out to be bad is superseded by a new patch version, never rewritten.

## Sentry issues of a fix (JOOXCR-714)

A fix is finished only when its Sentry issue is resolved against the release that carries it. Fixed faults left unresolved keep counting against the signal and hide the live ones.

- **Where to find the issue.** The Jira ticket of a fault that came from Sentry names its issues (short id such as `XCRAWLER-4D` and the numeric id in `https://sentry.jooservices.com/organizations/jooservices/issues/<id>/`). A fix for a fault without a Sentry reference is checked by searching the project for the exception message before the release is reported.
- **When.** After the release is rolled out to all three nodes (step 8) and the issue has produced no event from the nodes that run it.
- **How.** Resolve the issue *in release* with the release name of the tag, which is the full commit SHA written to `SENTRY_RELEASE` (`git rev-parse v<x.y.z>^{commit}`), and add a comment with the Jira key, the commit that fixed it and the file and line of the fix. In the Sentry API this is `PUT /api/0/projects/jooservices/xcrawler/issues/?id=<id>` with `{"status": "resolved", "statusDetails": {"inRelease": "<full sha>"}}`.
- **A resolved issue is not hidden.** Checked on a Sentry 26.11.0 test project: a new event on the release an issue was resolved in returns the issue to `unresolved`, both for a plain resolve and for a resolve `inRelease`. An issue that returns after being resolved means the fix is not complete; reopen the Jira ticket or open a Bug that links the issue.
- **Not a code fix.** When the fault was transient and nothing in the code changed (for example a short outage), say so in the comment and resolve only after checking that the affected data is correct now.

## Versioning (Semantic Versioning)

- New features -> minor (`1.1.0`); fixes -> patch (`1.0.1`); incompatible changes -> major (`2.0.0`).
- Database migrations must be backward compatible (add first, remove in a later release) so that rolling back to the previous tag always works. A release that contains a migration states it in its changelog section together with its rollback.

## Hotfix

1. Branch `hotfix/<x.y.z>` from `master`; fix, run all gates, add the changelog section.
2. Pull request into `master` (green gate above), merge, wait for the push CI, tag `v<x.y.z>-rc.1` / `v<x.y.z>`, canary and roll out as in the release flow.
3. Merge `master` back into `develop`.

## Rollback

Deploy the previous release tag with the same installer (`--ref v<previous>`; `--start-scheduler` on the primary). The installer restores the previous code and environment automatically when a deploy fails its own checks. No database restore is part of a rollback; never flush Redis or wipe databases.

## Version visibility

The installer sets `SENTRY_RELEASE` to the full commit SHA of the deployed release, so every Sentry event is tied to a commit; map it to a tag with `git tag --points-at <sha>`. `/api/health` does not report the version today; showing the release tag there is planned (JOOXCR-423).

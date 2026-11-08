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

## Release flow (the releaser is Claude, the owner does not release)

1. **Prepare.** All intended pull requests are merged into `develop`. Cut `release/<x.y.z>` from `develop` (or use `develop` itself when nothing else is waiting). Move the `[Unreleased]` entries of `CHANGELOG.md` under `## [x.y.z] - <date>` and write the release notes.
2. **Release pull request** `release/<x.y.z>` (or `develop`) -> `master`, title `chore(release): Release <x.y.z>`. Merge commit, never squash.
3. **Green gate (all required, no exceptions).** Every CI job is green: Lint and test, Integration, E2E journeys, Secrets scan, Build app image, commit message and pull request title validation (GitGuardian reports neutral by design). Zero unresolved review threads. Every comment of every reviewer, AI or bot (CodeRabbit, GitGuardian, Qodo, Copilot, ...) is read and either fixed or answered with the reason; no review requests changes. The releaser has also run all steps of `.github/workflows/ci.yml` locally and checked the diff and the tests. Anything not green means no release.
4. **Merge** the release pull request into `master`.
5. **Release candidate.** Tag the merge commit `v<x.y.z>-rc.1` (annotated tag, pushed). The `Release` workflow checks that the tag is on `master` and that the required checks are green on that commit, then publishes a pre-release.
6. **Canary.** Capture the production baseline (Sentry groups, Prometheus alerts, `/api/health`, PostgreSQL connections, release running on each node). Preview, then apply the candidate on `.20` only: `deploy/production.sh --ref v<x.y.z>-rc.1` (preview first, `--apply` second). Watch at least 10 minutes: Sentry new groups, error rate, alerts, PostgreSQL and Redis connections, Horizon workers, `/app`, `/api/health`, `/ui-api/status/summary`.
7. **Final tag.** If the canary is healthy, tag the SAME commit `v<x.y.z>` and push it (the GitHub Release is created by the workflow). Tags are never moved or deleted.
8. **Roll out** `.21` and `.22` with `--ref v<x.y.z>`; verify all three nodes run the same release (`readlink /var/www/xcrawler/current`).
9. **Merge back.** Open a pull request `master` -> `develop` (merge commit) so `develop` contains the release merge commit; then delete `release/<x.y.z>`.
10. **Report** to the owner (Telegram at start, after the canary and at the end) and update the Jira tickets.

If the canary fails: roll back `.20` by deploying the previous tag, fix on `develop`, and publish the next release candidate (`-rc.2`). A published tag that turns out to be bad is superseded by a new patch version, not rewritten.

## Versioning (Semantic Versioning)

- New features -> minor (`1.1.0`); fixes -> patch (`1.0.1`); incompatible changes -> major (`2.0.0`).
- Release candidates: `vX.Y.Z-rc.N`; pre-release GitHub Releases.
- Database migrations must be backward compatible (add first, remove in a later release) so that rolling back to the previous tag always works. A release that contains a migration states it in the release notes together with its rollback.

## Hotfix

1. Branch `hotfix/<x.y.z>` from `master`; fix, run all gates.
2. Pull request into `master` (green gate above), merge, tag `v<x.y.z>-rc.1` / `v<x.y.z>`, canary and roll out as in the release flow.
3. Merge `master` back into `develop`.

## Rollback

Deploy the previous release tag with the same installer (`--ref v<previous>`). The installer restores the previous code and environment automatically when a deploy fails its own checks. No database restore is part of a rollback; never flush Redis or wipe databases.

## Version visibility

The deployed tag is the Sentry `release` (`SENTRY_RELEASE`) and is shown by `/api/health`, so every error is tied to a release.

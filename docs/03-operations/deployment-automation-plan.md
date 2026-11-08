# GitHub Actions deployment automation plan

Status: Approved plan; implementation deferred.

This document records the agreed deployment design for javmeta. It is a plan,
not an indication that the automation currently exists or has been verified in
production.

## 1. Context

javmeta is deployed to six application nodes:

- one control node;
- five crawler nodes.

The control node runs the public and coordinating services. Each crawler node
runs crawl workers and the browser-related services needed by those workers.
The nodes share external data services.

GitHub Actions is the deployment orchestrator. There is no dedicated deployer
server. A GitHub-hosted runner connects directly to every target over SSH,
selects the correct flow from the node role, and stops on the first failure.

The initial automation remains source-driven:

- existing checkouts are updated with `git pull --ff-only`;
- the checked-out commit must equal the release commit expected by GitHub
  Actions;
- Composer and any role-specific dependencies are installed on the target;
- services are restarted from the updated checkout.

The following capabilities are explicitly deferred to later work:

- cloning a missing checkout;
- preparing a second release directory before downtime;
- immutable registry images;
- a dedicated deployer server;
- Ansible;
- automatic rollback;
- zero-downtime deployment.

The accepted consequence is that Git, Composer, or dependency failures can
leave the system in maintenance after the deployment has stopped services.
The operator resolves such failures manually and resumes or reruns the
deployment.

## 2. Node roles

### Control

The single control node owns:

- scheduler;
- public API;
- MCP service;
- embedder;
- the one-per-deployment database migration.

The scheduler must never run on a crawler node and must remain stopped from the
start of quiescing until the complete deployment has passed verification.

### Crawler

Each of the five crawler nodes owns:

- crawl workers;
- browser runtime;
- FlareSolverr;
- node-specific worker concurrency.

Crawlers are upgraded and verified one at a time after the new control runtime
has passed its internal checks.

## 3. Business requirements

### BR-01 — Release-driven deployment

Publishing an approved GitHub release starts the production deployment
workflow. A manual workflow entry may be provided for an operator-controlled
retry of the same release.

### BR-02 — Role-aware targets

The production inventory identifies every target as `control` or `crawler`.
The workflow must execute only the commands allowed for that role.

### BR-03 — Safe crawl quiescing

Before migration, the scheduler stops and all crawler workers stop accepting
new jobs. Every active worker is allowed to finish only its current job and
then exit. Jobs still waiting in the queue remain queued.

### BR-04 — Single migration execution

The database migration runs exactly once, on the control node, after every old
crawler worker has stopped.

### BR-05 — Sequential crawler rollout

Crawler nodes are upgraded sequentially. A crawler must pass all checks before
the next crawler is touched.

### BR-06 — Fail closed

After quiescing begins, any failed deployment step leaves the application in
maintenance, the scheduler stopped, and all crawler workers stopped. The
workflow reports failure and waits for manual human resolution. It must not
attempt automatic rollback or automatically restore traffic.

### BR-07 — Exact release identity

Every target must finish on the Git commit associated with the GitHub release.
A successful `git pull` is insufficient unless `git rev-parse HEAD` matches the
expected commit SHA.

### BR-08 — Observable result

The GitHub Actions run must show the release, expected SHA, target, role,
current phase, verification result, and final deployment status without
printing secrets or runtime environment contents.

## 4. Technical requirements

### TR-01 — Production GitHub environment

The workflow uses the protected GitHub environment `production`. At minimum it
provides:

- deployment SSH private key;
- pinned SSH `known_hosts` content;
- production target inventory;
- any non-secret deployment settings.

The deploy job must never run for pull requests. Environment approval may be
used to pause a release before production access is granted.

### TR-02 — Target inventory

The inventory is a validated JSON array stored as an environment secret or
variable. Each entry contains:

- stable node name;
- hostname or IP;
- SSH port;
- SSH user;
- role (`control` or `crawler` only);
- optional role settings such as worker count and drain timeout.

The workflow must require exactly one enabled control target and five enabled
crawler targets. Values are parsed with `jq`, strictly quoted, and never passed
through `eval`.

### TR-03 — SSH controls

- Use a deployment-specific SSH key.
- Require `StrictHostKeyChecking=yes`.
- Use the pinned `known_hosts`; do not trust a key discovered during the run.
- Disable interactive prompts and password authentication.
- Do not use agent forwarding.
- Restrict the remote deployment user and its `sudo` permissions to the javmeta
  service commands required by this plan.
- Remove temporary private-key files with a shell trap.

### TR-04 — Deployment concurrency

Only one production deployment may execute at a time:

```yaml
concurrency:
  group: javmeta-production
  cancel-in-progress: false
```

An in-progress deployment must not be cancelled merely because another release
or retry is queued.

### TR-05 — Repository update safety

Before pulling, every node must pass:

- configured application directory exists and contains `.git`;
- current branch is the configured deployment branch;
- tracked and untracked working tree is clean;
- remote is the expected repository;
- required runtime environment file exists;
- sufficient disk space is available.

Update with `git pull --ff-only`. After pulling, require:

```bash
test "$(git rev-parse HEAD)" = "${EXPECTED_SHA}"
```

A mismatch is a deployment failure.

### TR-06 — Dependency and Laravel commands

The control update runs, in order:

```text
git pull --ff-only
verify expected SHA
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force --isolated
php artisan migrate:status
php artisan optimize
```

Each crawler update runs the same sequence without migration:

```text
git pull --ff-only
verify expected SHA
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan optimize:clear
php artisan optimize
```

Role-specific Python or browser dependencies must be handled by their existing
role deployment command when applicable. Commands must be non-interactive and
return non-zero on failure.

The effect of `optimize:clear` on the configured shared cache must be verified
before implementation. It must not remove business data.

### TR-07 — Graceful worker drain

Stopping the scheduler does not drain queued work; workers could continue
taking jobs already in the queue. The workflow therefore gracefully stops the
worker service on all five crawler nodes after stopping the scheduler.

For Docker Compose workers, the intended mechanism is equivalent to:

```bash
docker compose stop --timeout 300 worker
```

Requirements:

- the worker supervisor forwards `SIGTERM` to every child worker;
- an idle worker exits immediately;
- a busy worker finishes its current job and exits without reserving another;
- a manual service stop does not cause the process manager to restart it;
- the stop timeout exceeds the longest supported job duration plus a safety
  margin;
- every worker process on every crawler must exit, not merely one process per
  node.

The five nodes may drain concurrently. The workflow waits for all five and
then confirms that no worker container or worker process remains running.

If any worker cannot drain within the allowed period, deployment stops before
migration. The script must not intentionally force-kill a running crawl job.

### TR-08 — Maintenance and activation

After all crawlers have drained:

- run `php artisan down` on control;
- stop the control API, MCP, and embedder services;
- keep the scheduler stopped;
- deploy and migrate control;
- restart API, MCP, and embedder while maintenance remains enabled;
- verify control internally through loopback or an approved maintenance
  bypass;
- deploy and verify crawlers one at a time;
- run `php artisan up` only after all crawlers pass;
- verify the public API;
- start the scheduler last.

### TR-09 — Failure state

Shell entry points use `set -Eeuo pipefail` and report the failed phase and
target. After quiescing has begun, any failure results in:

- Laravel maintenance remains enabled;
- scheduler remains stopped;
- all crawler workers remain stopped;
- remaining crawler targets are not deployed;
- GitHub Actions ends as failed;
- a human diagnoses and resolves the state manually.

There is no automatic rollback in the initial implementation.

## 5. Approved deployment sequence

1. GitHub Release triggers the production workflow.
2. Resolve the release tag to `EXPECTED_SHA`.
3. Load and validate the SSH key, pinned host keys, and six-target inventory.
4. Confirm exactly one control and five crawlers are reachable.
5. Confirm no other production deployment is running.
6. Stop the scheduler on control.
7. Gracefully stop workers on all five crawlers concurrently.
8. Wait for every active worker to finish only its current job.
9. Confirm no worker remains running on any crawler.
10. Stop crawler browser and FlareSolverr services.
11. Run `php artisan down` on control.
12. Stop control API, MCP, and embedder services.
13. On control, run the repository update and Composer installation.
14. Verify control `HEAD` equals `EXPECTED_SHA`.
15. Clear Laravel optimization caches.
16. Run `php artisan migrate --force --isolated` once on control.
17. Verify migration status.
18. Rebuild Laravel optimization caches.
19. Start control API, MCP, and embedder; keep maintenance and scheduler off.
20. Verify control internally.
21. Update crawler 1, start its services, and verify it.
22. Repeat step 21 for crawlers 2 through 5, one at a time.
23. Run `php artisan up` on control.
24. Verify the public API.
25. Start the scheduler.
26. Verify scheduler activity and queue processing.
27. Record the deployment as successful.

Compact form:

```text
release
→ validate targets
→ stop scheduler
→ drain all crawler workers
→ stop crawler dependencies
→ maintenance and stop control
→ pull/install/migrate control once
→ start and verify control internally
→ pull/install/start/verify each crawler sequentially
→ bring control online
→ verify public API
→ start scheduler
→ final verification
```

## 6. Verification requirements

### Control internal verification

- checked-out SHA equals the release SHA;
- migration status has no unexpected pending migration;
- API process is running;
- database connection succeeds;
- MongoDB connection succeeds;
- Elasticsearch connection succeeds;
- embedder health and one embedding request succeed;
- MCP process and smoke request succeed;
- scheduler is still stopped;
- Laravel is still in maintenance.

### Per-crawler verification

- checked-out SHA equals the release SHA;
- expected worker process count is running;
- worker heartbeat is current;
- database and control embedder are reachable;
- browser runtime responds;
- FlareSolverr responds;
- a bounded smoke crawl succeeds;
- no startup error is present in recent logs.

### Final verification

- Laravel maintenance is disabled;
- public API health succeeds;
- all five crawlers run the release SHA;
- all crawler heartbeats are current;
- scheduler is running on control only;
- new work is dispatched and consumed;
- no abnormal deployment error appears in logs.

Every verification has a bounded timeout and returns non-zero on failure.

## 7. Failure scenarios

### Failure before quiescing

The workflow fails without changing production service state.

### Worker drain failure

Do not start migration. Report the affected node and wait for human action.
The implementation must define whether the operator resumes the old services
or retries the drain; automation does not force-kill the job.

### Git, Composer, or dependency failure after services stop

Keep maintenance, scheduler, and all workers stopped. Report the failed target
and command. Wait for manual repair and workflow retry.

### Migration failure

Keep all services stopped or control in diagnostic maintenance mode. Do not
run an automatic database or source rollback. Wait for manual fix-forward.

### Crawler verification failure

Stop rollout before touching the next crawler. Keep maintenance, scheduler,
and all crawler workers stopped. Wait for manual resolution.

### Final control or scheduler verification failure

Report failure and keep the safest current state. Do not declare success or
continue automatically.

## 8. Security and operational constraints

- Never print SSH private keys, `.env`, credentials, or complete secret JSON.
- Pin all GitHub Actions by immutable commit SHA.
- Grant the workflow only the minimum GitHub token permissions it needs.
- Restrict production deployment to an approved release/tag source.
- Do not use a self-hosted GitHub runner on a production node.
- Do not restart the Docker daemon or the entire Supervisor service.
- Restart or stop only javmeta process groups and Compose services.
- Never remove volumes, prune Docker state, or delete queued jobs as part of
  deployment.
- Preserve logs needed for manual recovery.

## 9. Planned implementation tasks

Implementation must not begin until the owner explicitly resumes this plan.

### Task DEP-01 — Define the production workflow contract

**Scope:** Add the release/manual workflow skeleton, production environment
contract, concurrency rule, and strict input validation. Do not execute remote
deployment yet.

**Acceptance criteria:**

- release tag resolves to one expected commit SHA;
- inventory validation requires one control and five crawlers;
- deployment cannot run concurrently;
- pull requests cannot access or invoke the production deploy job.

**Verification:** actionlint, zizmor, and workflow dry-run review.

**Dependencies:** None.

### Task DEP-02 — Implement shared SSH and repository safety

**Scope:** Implement pinned host verification, SSH lifecycle, clean-worktree
checks, fast-forward-only pull, expected-SHA verification, structured logging,
and safe command failure propagation.

**Acceptance criteria:**

- wrong host key, dirty tree, wrong branch, wrong remote, pull failure, or SHA
  mismatch fails before the next phase;
- secrets are absent from logs;
- all temporary key material is removed.

**Verification:** shell tests with mocked SSH/Git success and failure cases.

**Dependencies:** DEP-01.

### Task DEP-03 — Implement and prove graceful crawler drain

**Scope:** Stop the scheduler, gracefully terminate every child worker on all
five crawler nodes, wait for current jobs, verify zero remaining workers, and
stop crawler dependencies.

**Acceptance criteria:**

- a busy worker finishes its current job and takes no next job;
- an idle worker exits promptly;
- all worker slots and replicas stop;
- drain timeout fails before migration and does not deliberately kill the
  active job.

**Verification:** shell/integration test with idle, active, multiple-worker,
timeout, and partial-node-failure cases.

**Dependencies:** DEP-02.

### Task DEP-04 — Implement control deployment

**Scope:** Enter maintenance, stop control services, pull the release, install
dependencies, clear caches, migrate once, optimize, restart non-scheduler
control services, and run internal verification.

**Acceptance criteria:**

- migration executes once per workflow;
- scheduler remains stopped;
- maintenance remains enabled;
- every internal control check must pass before crawler rollout begins.

**Verification:** controlled test deployment against a non-production stack,
including injected Composer, migration, and health-check failures.

**Dependencies:** DEP-03.

### Task DEP-05 — Implement sequential crawler deployment

**Scope:** Pull, install, optimize, start, and verify each crawler in inventory
order, stopping on the first failure.

**Acceptance criteria:**

- only one crawler is changed at a time;
- failed crawler verification leaves later crawlers untouched;
- successful verification covers worker, heartbeat, browser, FlareSolverr,
  database, embedder, smoke crawl, and release SHA.

**Verification:** injected failure on crawler 2 proves crawlers 3–5 remain
untouched.

**Dependencies:** DEP-04.

### Task DEP-06 — Implement final activation and failure state

**Scope:** Disable maintenance, verify the public API, start the scheduler,
verify dispatch/consumption, and enforce the agreed manual-recovery state on
any failure.

**Acceptance criteria:**

- `artisan up` occurs only after all five crawlers pass;
- scheduler starts only after the public API passes;
- any failure is visible in GitHub Actions and never reports success;
- there is no automatic rollback or automatic traffic restoration.

**Verification:** success-path rehearsal and failure injection at each final
activation step.

**Dependencies:** DEP-05.

### Task DEP-07 — Document and rehearse operations

**Scope:** Update the production runbook with setup, normal deployment,
failure diagnosis, manual resume, and secret rotation. Rehearse the complete
flow before production enablement.

**Acceptance criteria:**

- an operator can identify the stopped phase and failed node;
- the runbook explains how to inspect and manually resume without deleting
  queue or database data;
- one full non-production rehearsal and one controlled production rollout are
  recorded.

**Verification:** operator walkthrough and signed deployment checklist.

**Dependencies:** DEP-06.

## 10. Definition of done

- A GitHub release can deploy one control and five crawlers without a
  dedicated deployer server.
- The workflow uses the approved production environment and strict SSH host
  verification.
- All old worker processes finish their current job and stop before migration.
- Migration runs once and only after all crawlers have stopped.
- Crawlers deploy and verify sequentially.
- Control becomes public and scheduler starts only after every crawler passes.
- A failure leaves maintenance enabled and processing stopped for manual human
  resolution.
- Git SHA and deployment result are visible for every node.
- The full flow and its failure paths have been rehearsed outside production.

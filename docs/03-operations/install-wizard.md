# Install and deploy wizard

`deploy/install.sh`, interactive production and unattended staging wrappers share one
manifest-driven engine. Follow the [native installation guide](../../deploy/production/README.md)
for private answers, roles, UTC/ACL checks, backup gate, immutable releases,
owner credentials and rollback. Dry-run is the default. No ref is implied.

Application settings stay in the database/Settings UI; initial deployment
answers initialize missing registered values. Secrets never belong in argv,
documentation, logs or version control. Local proof must use disposable
namespaces and `--skip-os-install --no-systemd --no-start`; it does not prove
production readiness or a running scheduler.

After installing Horizon on each main or worker node, install and enable
`deploy/systemd/javmeta-horizon-reconcile.service` and
`javmeta-horizon-reconcile.timer`. Worker nodes intentionally do not run the
application scheduler, so the timer is required there. The timer is local to
each node and is safe to run alongside the main scheduler's once-a-minute
reconcile command.

For release application, the wizard runs `health:startup` against the staged
release before stopping existing units. A failed primary check leaves those
units and `current` untouched. Once it passes, the wizard quiesces units,
applies migrations and shared settings, and switches `current`; later failures
use the existing rollback. Horizon, scheduler and embedder systemd units also
run the role-specific check from `ExecStartPre`. Because the Horizon and
scheduler units use `Restart=on-failure`, use `systemctl restart` for clean-exit
maintenance reloads. See [startup health](startup-health.md).

## Completion evidence and remaining gaps

[JOOXCR-382](https://jooservices.atlassian.net/browse/JOOXCR-382) asks for the Command Center (primary) and node (worker)
install paths to complete in a clean Ubuntu 24.04 Docker container on WSL, to be idempotent, to report clear errors and to
print no secrets. The current evidence is partial; shell fixtures do not establish that full acceptance.

| Area | Reproducible evidence | What it does not prove |
| --- | --- | --- |
| Role support | [`deploy/install.sh`](../../deploy/install.sh) accepts `primary` and `worker`; [`deploy/lib/install.sh`](../../deploy/lib/install.sh) keeps migrations and shared setup on primary. [`tests/Shell/roles.test.sh`](../../tests/Shell/roles.test.sh), [`tests/Shell/node-template.test.sh`](../../tests/Shell/node-template.test.sh) and [`tests/Shell/install.test.sh`](../../tests/Shell/install.test.sh) cover role selection, worker bootstrap and unit configuration. | These are local shell tests with stubbed services and commands; they do not install either role on a clean Ubuntu host. |
| Installer repeat | `tests/Shell/install.test.sh` reruns the wizard against an existing fixture and checks that its environment and owner credentials are preserved. | This simulated repeat does not prove a clean-host installation, OS/package setup or started-service readiness. |
| Command Center enrollment | [`routes/web.php`](../../routes/web.php), [`NodeEnrollmentController`](../../app/Http/Controllers/Nodes/NodeEnrollmentController.php) and [`NodeEnrollmentService`](../../app/Services/Nodes/NodeEnrollmentService.php) expose join-script delivery and one-time token redemption. [`NodeEnrollmentEndpointsTest`](../../tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php) checks a successful worker redemption and that replay is rejected. | This is endpoint coverage; it does not run the downloaded script through the Command Center and into a completed install. Token redemption is intentionally one-time, so replaying a join token is not an installer idempotence test. |
| Join-script guards | [`tests/Shell/join-node.test.sh`](../../tests/Shell/join-node.test.sh) checks malformed inputs, HTTPS enforcement, simulated Ubuntu-version checks and `--plan`. | The test supplies a fixture `os-release` file and stops before network redemption. It does not exercise apt, the Command Center, the installer or a real Ubuntu container. |
| Error messages and secret output | [`tests/Shell/install.test.sh`](../../tests/Shell/install.test.sh) injects installer failures and checks diagnostic output and that fixture credentials are absent from logs. `tests/Shell/join-node.test.sh` checks refusal messages and that help output does not echo its token fixture. | The checks exercise selected failures with local stubs; they do not establish error handling for every failure in a full clean-host install. |
| Clean Ubuntu worker rehearsal | [JOOXCR-386](https://jooservices.atlassian.net/browse/JOOXCR-386) is the existing worker-rehearsal follow-up. Its current status is In Progress; its description records that the Ubuntu 24.04 rehearsal stops before installation because the QA services lack required authenticated endpoints. | The clean Ubuntu node acceptance remains pending until a successful end-to-end result is recorded. |

### Bounded follow-ups

1. **Command Center / primary:** run the primary install and a deliberate same-host rerun in a disposable clean Ubuntu 24.04 container. Verify prerequisites, successful readiness, preserved configuration and owner credentials, clear failure output and no secret output. Record the exact command options and results. This primary-path rehearsal is separate from JOOXCR-386.
2. **Node / worker:** finish the existing [JOOXCR-386](https://jooservices.atlassian.net/browse/JOOXCR-386) rehearsal after its QA dependencies are ready. Use a fresh one-time enrollment for the actual join flow, verify the worker appears in Command Center, then test installer rerun separately without reusing the consumed token. Record pass/fail evidence there; do not create a duplicate node-rehearsal ticket.

Both rehearsals need isolated QA services and disposable Ubuntu containers. Obtain approval before changing shared QA infrastructure or running the rehearsal; neither rehearsal may target production hosts. This documentation change performed neither rehearsal.

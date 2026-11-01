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

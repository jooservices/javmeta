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

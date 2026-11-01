# Staging services (WSL)

Two systemd user units keep JavMeta running 24/7 on the staging machine. Both run `php artisan` inside the shared PHP 8.5
runtime with `scripts/docker/runtime` (no private containers) and restart after a failure or a reboot.

| Unit | What it runs | Notes |
|---|---|---|
| `javmeta-horizon.service` | `php artisan horizon` | stopped with `horizon:terminate`, so running jobs finish |
| `javmeta-scheduler.service` | `php artisan schedule:work` | exactly one instance; polls the DB feed interval every five seconds, without restart |
| `javmeta-horizon-reconcile.timer` | `php artisan horizon:reconcile-workers` | one timer on every main and worker node; compares the local Horizon boot layout with settings in Redis and restarts only that node after stable jitter |

Install (once, as the staging user; the deploy script restarts them after each deployment):

```bash
mkdir -p ~/.config/systemd/user
cp deploy/systemd/javmeta-* ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now javmeta-horizon.service javmeta-scheduler.service javmeta-horizon-reconcile.timer
```

The web API and MCP are served by the shared web container (`scripts/docker/web-site`), not by these units.
Enable the reconcile timer on worker nodes too; they do not run the scheduler. A deploy lock suppresses reconciliation,
each node has a 10-minute restart cooldown, and a stable node-specific jitter spreads restarts. Check:
`php artisan horizon:status` and `systemctl --user status javmeta-horizon javmeta-scheduler javmeta-horizon-reconcile.timer`.

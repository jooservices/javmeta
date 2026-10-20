# Staging services (WSL)

Two systemd user units keep JavMeta running 24/7 on the staging machine. Both run `php artisan` inside the shared PHP 8.5
runtime with `scripts/docker/runtime` (no private containers) and restart after a failure or a reboot.

| Unit | What it runs | Notes |
|---|---|---|
| `javmeta-horizon.service` | `php artisan horizon` | stopped with `horizon:terminate`, so running jobs finish |
| `javmeta-scheduler.service` | `php artisan schedule:work` | exactly one instance; it also feeds the crawl pool every minute |

Install (once, as the staging user; the deploy script restarts them after each deployment):

```bash
mkdir -p ~/.config/systemd/user
cp deploy/systemd/javmeta-*.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now javmeta-horizon.service javmeta-scheduler.service
```

The web API and MCP are served by the shared web container (`scripts/docker/web-site`), not by these units.
Check: `php artisan horizon:status` and `systemctl --user status javmeta-horizon javmeta-scheduler`.

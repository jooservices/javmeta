# JOOservices javmeta project rules

Adds to the workspace `AGENTS.md`. Does not weaken any workspace rule.

## Production VMs — read-only unless confirmed

The javmeta production topology is maintained in the private workspace
deployment inventory. This file intentionally omits production IP addresses.
The control plane runs on one instance; crawler workers run on the crawler
instances.

- **Any write action requires explicit user confirmation first.** Write actions
  include: `git pull` / fetch / checkout, `docker compose build|up|run` that
  changes state, `.env` edits, `php artisan migrate|es:setup|tinker` that
  writes, container restart / recreate / remove, and deleting or removing data.
- **Read-only** investigation is always allowed: logs, `docker ps`, `git log`,
  `curl <health>`, non-writing queries.
- When the user says "confirm before execute", present the exact commands and
  wait for approval before running them.

## GitHub access on production VMs — gh CLI only

All javmeta VMs authenticate to GitHub via the **`gh` CLI** (account
`soulevilx`), not SSH deploy keys.

- Before any `gh` / git fetch on a VM: `gh auth switch --user soulevilx` and
  verify `gh api user --jq .login` returns `soulevilx`.
- Do not add or rely on SSH deploy keys for javmeta VMs.

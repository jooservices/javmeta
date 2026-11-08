#!/usr/bin/env bash
# Kills Horizon and the scheduler with SIGKILL on staging and waits (up to 10 minutes) for staging:readiness to return
# to READY without anyone restarting anything: systemd restarts the units, crawler:reclaim frees the jobs of dead workers.
# Run it on the WSL host from the staging clone:  deploy/chaos-check.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTISAN=(docker exec -u 1000:1000 -w "${ROOT}" -e HOME=/tmp workspaces-cli-php-8.5 php artisan)
UNITS=(javmeta-horizon javmeta-scheduler)
LIMIT_SECONDS="${CHAOS_LIMIT_SECONDS:-600}"

log() { printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }

if ! "${ARTISAN[@]}" staging:readiness --no-record >/dev/null 2>&1; then
  log "Staging is not READY before the test; fix that first."
  exit 2
fi

log "Killing ${UNITS[*]} with SIGKILL."
systemctl --user kill -s KILL "${UNITS[@]}"

started=$SECONDS
while (( SECONDS - started < LIMIT_SECONDS )); do
  sleep 20
  if "${ARTISAN[@]}" staging:readiness --no-record >/dev/null 2>&1; then
    log "Staging is READY again after $((SECONDS - started)) seconds without human action."
    exit 0
  fi
done

log "Staging did not return to READY within ${LIMIT_SECONDS} seconds."
"${ARTISAN[@]}" staging:readiness --no-record || true
exit 1

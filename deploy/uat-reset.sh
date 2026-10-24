#!/usr/bin/env bash
# Put the UAT environment back to a known state: empty its database and load the sample dataset into it.
#
#   deploy/uat-reset.sh uat [--dry-run]
#
# It refuses to run against anything but the uat target, and refuses when the environment's database name is the
# staging one: the sample rows must never reach the data of the live crawl.
set -Eeuo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/environments.sh
source "${SCRIPT_DIR}/environments.sh"

target="${1:-}"
dry_run=0
[[ "${2:-}" == '--dry-run' ]] && dry_run=1

log() { printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

[[ "${target}" == 'uat' ]] || die "The reset only works on the uat target (got '${target:-nothing}'); it refuses to touch staging."
load_environment uat
POSTGRES_DATABASE="${JAVMETA_DEPLOY_POSTGRES_DATABASE:-${POSTGRES_DATABASE_DEFAULT}}"
APP_DIR="${JAVMETA_APP_DIR:-${APP_DIR_DEFAULT}}"

load_environment staging
STAGING_DATABASE="${POSTGRES_DATABASE_DEFAULT}"
[[ "${POSTGRES_DATABASE}" != "${STAGING_DATABASE}" ]] || die "The UAT database name (${POSTGRES_DATABASE}) is the staging one; refusing."
[[ "${POSTGRES_DATABASE}" == *_uat ]] || die "The UAT database name must end with _uat (got ${POSTGRES_DATABASE})."

WORKSPACES_ROOT="${WORKSPACES_ROOT:-${HOME}/Workspaces}"
RUNTIME="${WORKSPACES_ROOT}/scripts/docker/runtime"

run() {
  if (( dry_run == 1 )); then
    log "DRY RUN: $*"
    return 0
  fi
  "$@"
}

[[ -d "${APP_DIR}" ]] || { (( dry_run == 1 )) || die "${APP_DIR} does not exist: the UAT clone is not set up."; }
log "Resetting UAT: database ${POSTGRES_DATABASE}, app ${APP_DIR}."
run systemctl --user stop javmeta-uat-scheduler.service javmeta-uat-horizon.service
(cd "${APP_DIR}" 2>/dev/null || true; run "${RUNTIME}" php@8.5 php "${APP_DIR}/artisan" migrate:fresh --force)
(cd "${APP_DIR}" 2>/dev/null || true; run "${RUNTIME}" php@8.5 php "${APP_DIR}/artisan" dataset:load sample --index)
run systemctl --user start javmeta-uat-horizon.service javmeta-uat-scheduler.service
log 'UAT is back to the sample dataset.'

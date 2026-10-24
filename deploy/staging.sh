#!/usr/bin/env bash
# Update the JavMeta staging environment on the WSL main clone (branch develop), safely and without a person.
#
#   deploy/staging.sh [staging|uat] [--dry-run]
#
# The target defaults to staging. The names that must differ between the two (database, Mongo, Elasticsearch, Redis,
# port, clone, systemd units) are in deploy/environments.sh.
#
# Steps: check the branch, the clean tree and the .env, take a quick Postgres backup, pull with fast-forward only,
# install dependencies with the shared PHP runtime, migrate forward only, set up Elasticsearch, refresh the caches,
# restart the JavMeta systemd user units (scheduler stopped first and started last) and run the smoke checks.
# A failure after the pull returns the code to the previous commit and, when the migration failed, restores the backup.
# The script has no trigger of its own: it never runs on merge. It starts no Docker container.
set -Eeuo pipefail
# Normal permissions: the web server must read what the pull and composer write. Only the backups are private (see below).
umask 022

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/environments.sh
source "${SCRIPT_DIR}/environments.sh"

target=staging
case "${1:-}" in staging|uat) target="$1"; shift ;; esac
load_environment "${target}"

APP_DIR="${JAVMETA_APP_DIR:-${APP_DIR_DEFAULT}}"
[[ "${target}" == staging ]] && APP_DIR="${JAVMETA_APP_DIR:-$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)}"
WORKSPACES_ROOT="${WORKSPACES_ROOT:-$(CDPATH='' cd -- "${APP_DIR}/../../.." && pwd)}"
RUNTIME="${WORKSPACES_ROOT}/scripts/docker/runtime"
SHARED="${WORKSPACES_ROOT}/scripts/docker/shared"
ENV_RUN="${WORKSPACES_ROOT}/scripts/docker/env-run"
STATE_DIR="${JAVMETA_DEPLOY_STATE_DIR:-${STATE_DIR_DEFAULT}}"
BACKUP_KEEP="${JAVMETA_DEPLOY_BACKUP_KEEP:-7}"
POSTGRES_DATABASE="${JAVMETA_DEPLOY_POSTGRES_DATABASE:-${POSTGRES_DATABASE_DEFAULT}}"
MEDIA_UNIT="${UNIT_PREFIX}-media-worker.service"
UNITS=("${HORIZON_UNIT}" "${SCHEDULER_UNIT}" "${MEDIA_UNIT}")

dry_run=0
case "${1:-}" in
  '') ;;
  --dry-run) dry_run=1 ;;
  --help|-h) sed -n '2,10p' "${BASH_SOURCE[0]}"; exit 0 ;;
  *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
esac

timestamp() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
log() { printf '%s %s\n' "$(timestamp)" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

# Run a command, or only print it in dry-run mode.
run() {
  if (( dry_run == 1 )); then
    log "DRY RUN: $*"
    return 0
  fi
  "$@"
}

artisan() { (cd "${APP_DIR}" && run "${RUNTIME}" php@8.5 php artisan "$@"); }

previous_commit=''
backup_file=''
pulled=0
active_units_before=()
migrate_started=0

rollback() {
  local status=$?
  trap - ERR
  log "Deployment failed (exit ${status}); rolling back."
  if (( pulled == 1 )) && [[ -n "${previous_commit}" ]]; then
    git -C "${APP_DIR}" reset --hard "${previous_commit}" || log 'WARNING: could not return to the previous commit.'
    log "Code returned to ${previous_commit}."
  fi
  if (( migrate_started == 1 )) && [[ -n "${backup_file}" && -s "${backup_file}" ]]; then
    log 'The migration did not finish: restoring the quick backup.'
    # shellcheck disable=SC2016  # the variables expand inside the Postgres container, not here
    if "${ENV_RUN}" exec -T postgres sh -ec \
      'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore --clean --if-exists --no-owner -h 127.0.0.1 -U "$POSTGRES_USER" -d "$1"' sh "${POSTGRES_DATABASE}" < "${backup_file}"; then
      log 'Backup restored.'
    else
      log 'WARNING: the backup could not be restored; the backup file is kept for a manual restore.'
    fi
  fi
  if (( pulled == 1 )); then
    (cd "${APP_DIR}" && "${RUNTIME}" php@8.5 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader) \
      || log 'WARNING: dependencies of the previous commit could not be reinstalled.'
  fi
  for unit in "${active_units_before[@]}"; do
    systemctl --user start "$unit" 2>/dev/null \
      || log "WARNING: could not restart ${unit} during rollback."
  done
  exit "${status}"
}

main() {
  mkdir -p "${STATE_DIR}/backups"
  chmod 700 "${STATE_DIR}" "${STATE_DIR}/backups"
  log_file="${STATE_DIR}/${target}-$(date -u '+%Y%m%dT%H%M%SZ').log"
  exec > >(tee -a "${log_file}") 2>&1
  log "JavMeta ${target} deployment starts (dry run: ${dry_run}); log: ${log_file}"

  [[ -d "${APP_DIR}/.git" ]] || die "${APP_DIR} is not a git clone."
  [[ "$(git -C "${APP_DIR}" rev-parse --abbrev-ref HEAD)" == 'develop' ]] || die 'The staging clone must be on branch develop.'
  [[ -z "$(git -C "${APP_DIR}" status --porcelain --untracked-files=no)" ]] || die 'The staging clone has uncommitted changes.'
  [[ -f "${APP_DIR}/.env" ]] || die 'The private .env of the staging clone is missing.'
  "${SHARED}" check-project javmeta --env-file "${APP_DIR}/.env" --mode live >/dev/null \
    || die 'check-project javmeta --mode live failed; the .env does not point at the live databases.'

  previous_commit="$(git -C "${APP_DIR}" rev-parse HEAD)"
  log "Current commit: ${previous_commit}"

  backup_file="${STATE_DIR}/backups/${POSTGRES_DATABASE}-$(date -u '+%Y%m%dT%H%M%SZ').dump"
  log "Quick backup of the ${POSTGRES_DATABASE} database."
  if (( dry_run == 1 )); then
    log "DRY RUN: pg_dump -Fc ${POSTGRES_DATABASE} > ${backup_file}"
  else
    # shellcheck disable=SC2016  # the variables expand inside the Postgres container, not here
    "${ENV_RUN}" exec -T postgres sh -ec \
      'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -Fc -h 127.0.0.1 -U "$POSTGRES_USER" "$1"' sh "${POSTGRES_DATABASE}" > "${backup_file}"
    [[ -s "${backup_file}" ]] || die 'The quick backup is empty.'
    chmod 600 "${backup_file}"
    # shellcheck disable=SC2012
    ls -1t "${STATE_DIR}/backups/"*.dump 2>/dev/null | tail -n +$((BACKUP_KEEP + 1)) | xargs -r rm -f --
  fi

  trap rollback ERR
  # Quiesce only the JavMeta services that were active before deployment.
  for unit in "${UNITS[@]}"; do
    if systemctl --user is-active --quiet "$unit"; then
      active_units_before+=("$unit")
      run systemctl --user stop "$unit"
    fi
  done
  run git -C "${APP_DIR}" fetch --prune origin
  run git -C "${APP_DIR}" pull --ff-only origin develop
  pulled=1
  log "Now at: $(git -C "${APP_DIR}" rev-parse HEAD 2>/dev/null || printf 'unknown')"

  (cd "${APP_DIR}" && run "${RUNTIME}" php@8.5 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader)

  migrate_started=1
  artisan migrate --force
  migrate_started=0

  artisan es:setup
  # Shared CLI and web runtimes mount the same code at different absolute paths;
  # cached Laravel paths generated in the CLI break web requests.
  # Skip cache:clear: with CACHE_STORE=redis it calls FLUSHDB and drops queued jobs.
  artisan optimize:clear --except=cache

  install_units
  log 'Restarting the JavMeta units: scheduler stopped first, started last.'
  run systemctl --user stop "${SCHEDULER_UNIT}"
  run systemctl --user restart "${HORIZON_UNIT}"
  run systemctl --user restart "${MEDIA_UNIT}"
  run systemctl --user start "${SCHEDULER_UNIT}"

  log 'Smoke checks.'
  if ! artisan javmeta:smoke; then
    # The web server and the workers need a few seconds after a restart: look once more before rolling back.
    log 'Smoke checks failed once; waiting 15 seconds and trying again.'
    run sleep 15
    artisan javmeta:smoke
  fi

  trap - ERR
  log 'Deployment finished.'
}

# First deployment: copy the units from the repository and enable them. Later deployments only reload them.
install_units() {
  local unit_dir="${HOME}/.config/systemd/user" unit
  run mkdir -p "${unit_dir}"
  for unit in "${UNITS[@]}"; do
    # The repository holds the staging units; the UAT units are the same with the UAT clone and a UAT name.
    local source_unit="${unit/#${UNIT_PREFIX}-/javmeta-}"
    if [[ -f "${APP_DIR}/deploy/systemd/${source_unit}" ]]; then
      if [[ "${target}" == staging ]]; then
        run cp -f "${APP_DIR}/deploy/systemd/${source_unit}" "${unit_dir}/${unit}"
      else
        run sed "s#JOOservices/projects/javmeta\$#JOOservices/projects/javmeta-uat#" "${APP_DIR}/deploy/systemd/${source_unit}" > "${unit_dir}/${unit}"
      fi
    fi
  done
  # Only staging has the backup timer for now.
  if [[ "${target}" == staging && -f "${APP_DIR}/deploy/systemd/javmeta-backup.timer" ]]; then
    run cp -f "${APP_DIR}/deploy/systemd/javmeta-backup.service" "${unit_dir}/javmeta-backup.service"
    run cp -f "${APP_DIR}/deploy/systemd/javmeta-backup.timer" "${unit_dir}/javmeta-backup.timer"
  fi
  run systemctl --user daemon-reload
  run systemctl --user enable "${UNITS[@]}"
  if [[ "${target}" == staging && -f "${unit_dir}/javmeta-backup.timer" ]]; then
    run systemctl --user enable --now javmeta-backup.timer
  fi
}

main "$@"

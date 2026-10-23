#!/usr/bin/env bash
# Prove that the newest staging backup restores: load it into a scratch database, count the tables and rows, drop it.
#
#   deploy/backup/staging-restore-check.sh
#
# Only the scratch database `javmeta_restore_check` is created and dropped; the live database is never touched.
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${JAVMETA_APP_DIR:-$(CDPATH='' cd -- "${SCRIPT_DIR}/../.." && pwd)}"
WORKSPACES_ROOT="${WORKSPACES_ROOT:-$(CDPATH='' cd -- "${APP_DIR}/../../.." && pwd)}"
ENV_RUN="${WORKSPACES_ROOT}/scripts/docker/env-run"
BACKUP_ROOT="${JAVMETA_BACKUP_ROOT:-${WORKSPACES_ROOT}/.tmp/javmeta-backups}"
DATABASE="${JAVMETA_BACKUP_POSTGRES_DATABASE:-javmeta}"
SCRATCH='javmeta_restore_check'

log() { printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

latest="$(ls -1dt "${BACKUP_ROOT}"/daily/*/ 2>/dev/null | head -n 1)"
[[ -n "${latest}" && -s "${latest}${DATABASE}.dump" ]] || die 'No backup found.'
(cd "${latest}" && sha256sum -c manifest.sha256 >/dev/null) || die 'The backup checksum does not match.'
log "Restoring ${latest}${DATABASE}.dump into ${SCRATCH}"

psql_in() {
  # shellcheck disable=SC2016
  "${ENV_RUN}" exec -T postgres sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" psql -v ON_ERROR_STOP=1 -h 127.0.0.1 -U "$POSTGRES_USER" -d "$1" -tA -c "$2"' sh "$1" "$2"
}
psql_in postgres "DROP DATABASE IF EXISTS ${SCRATCH}" >/dev/null
psql_in postgres "CREATE DATABASE ${SCRATCH}" >/dev/null
trap 'psql_in postgres "DROP DATABASE IF EXISTS ${SCRATCH}" >/dev/null 2>&1 || true' EXIT
# shellcheck disable=SC2016
"${ENV_RUN}" exec -T postgres sh -ec 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore --no-owner --exit-on-error -h 127.0.0.1 -U "$POSTGRES_USER" -d "$1"' sh "${SCRATCH}" < "${latest}${DATABASE}.dump"
tables="$(psql_in "${SCRATCH}" "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")"
movies="$(psql_in "${SCRATCH}" 'SELECT count(*) FROM movies')"
performers="$(psql_in "${SCRATCH}" 'SELECT count(*) FROM performers')"
log "Restore check passed: ${tables} tables, ${movies} movies, ${performers} performers."

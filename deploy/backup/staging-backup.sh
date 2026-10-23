#!/usr/bin/env bash
# Daily backup of the JavMeta staging database on the WSL workstation (shared PostgreSQL, no extra container).
#
#   deploy/backup/staging-backup.sh [--dry-run]
#
# Writes a custom-format pg_dump to <state>/backups/daily/<UTC time>/, keeps the last 7 and publishes the manifest the
# application reads (`javmeta:backup-status` uses storage/app/private/backups/latest.json, key created_at).
# MongoDB (raw page archive) and Elasticsearch (rebuildable from PostgreSQL) are listed as pending in the manifest.
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${JAVMETA_APP_DIR:-$(CDPATH='' cd -- "${SCRIPT_DIR}/../.." && pwd)}"
WORKSPACES_ROOT="${WORKSPACES_ROOT:-$(CDPATH='' cd -- "${APP_DIR}/../../.." && pwd)}"
ENV_RUN="${WORKSPACES_ROOT}/scripts/docker/env-run"
BACKUP_ROOT="${JAVMETA_BACKUP_ROOT:-${WORKSPACES_ROOT}/.tmp/javmeta-backups}"
KEEP="${JAVMETA_BACKUP_KEEP:-7}"
DATABASE="${JAVMETA_BACKUP_POSTGRES_DATABASE:-javmeta}"
MANIFEST="${JAVMETA_BACKUP_MANIFEST:-${APP_DIR}/storage/app/private/backups/latest.json}"

dry_run=0
[[ "${1:-}" == '--dry-run' ]] && dry_run=1

log() { printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

stamp="$(date -u '+%Y%m%dT%H%M%SZ')"
dir="${BACKUP_ROOT}/daily/${stamp}"
log "Backup of ${DATABASE} to ${dir}"
if (( dry_run == 1 )); then
  log "DRY RUN: pg_dump -Fc ${DATABASE} > ${dir}/${DATABASE}.dump; write ${MANIFEST}; keep ${KEEP}"
  exit 0
fi

mkdir -p "${dir}" "$(dirname "${MANIFEST}")"
# shellcheck disable=SC2016  # the variables expand inside the Postgres container, not here
"${ENV_RUN}" exec -T postgres sh -ec \
  'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -Fc -h 127.0.0.1 -U "$POSTGRES_USER" "$1"' sh "${DATABASE}" > "${dir}/${DATABASE}.dump"
[[ -s "${dir}/${DATABASE}.dump" ]] || die 'The dump is empty.'
(cd "${dir}" && sha256sum "${DATABASE}.dump" > manifest.sha256)
size="$(stat -c %s "${dir}/${DATABASE}.dump")"

created="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
cat > "${dir}/manifest.json" <<EOF
{"created_at":"${created}","namespace":"${DATABASE}","kind":"daily","components":["postgres"],"pending":["mongodb","elasticsearch"],"postgres_dump_bytes":${size}}
EOF
cp "${dir}/manifest.json" "${MANIFEST}.tmp"
chmod 644 "${MANIFEST}.tmp"            # the web user reads it through the ACL of the directory; keep it readable
mv -f -- "${MANIFEST}.tmp" "${MANIFEST}"

# retention: keep the newest $KEEP daily folders
# shellcheck disable=SC2012
ls -1dt "${BACKUP_ROOT}"/daily/*/ 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -rf --
log "Backup finished (${size} bytes)."

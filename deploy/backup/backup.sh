#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
# shellcheck source=deploy/backup/common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'USAGE'
Usage: backup.sh --namespace=<name> [--weekly] [--dry-run] [--prune-only]

Required environment: BACKUP_ROOT, AGE_RECIPIENT, PGHOST, PGPORT, PGUSER,
PGPASSWORD, PGDATABASE, MONGO_URI, ELASTICSEARCH_HOST, and
ELASTICSEARCH_SNAPSHOT_PATH.
USAGE
}

namespace=''
backup_kind='daily'
dry_run=0
prune_only=0

for argument in "$@"; do
  case "$argument" in
    --namespace=*) namespace="${argument#*=}" ;;
    --weekly) backup_kind='weekly' ;;
    --dry-run) dry_run=1 ;;
    --prune-only) prune_only=1 ;;
    --help|-h) usage; exit 0 ;;
    *) fail "Unknown argument: ${argument}" ;;
  esac
done

[[ -n "$namespace" ]] || { usage >&2; fail 'An explicit --namespace=<name> is required.'; }
validate_namespace "$namespace"
require_env BACKUP_ROOT
require_env PGHOST
require_env PGPORT
require_env PGUSER
require_env PGPASSWORD
require_env PGDATABASE
require_env MONGO_URI
require_env ELASTICSEARCH_HOST

mongo_database="$(mongo_database_from_uri "$MONGO_URI")"
[[ -n "$mongo_database" ]] || fail 'MONGO_URI must name its database in the path.'
index_pattern="${ELASTICSEARCH_INDEX_PATTERN:-${namespace}*}"
validate_namespace_targets "$namespace" "$mongo_database" "$index_pattern"

retention_daily="${BACKUP_RETENTION_DAILY:-7}"
retention_weekly="${BACKUP_RETENTION_WEEKLY:-4}"
[[ "$retention_daily" =~ ^[1-9][0-9]*$ ]] || fail 'BACKUP_RETENTION_DAILY must be a positive integer.'
[[ "$retention_weekly" =~ ^[1-9][0-9]*$ ]] || fail 'BACKUP_RETENTION_WEEKLY must be a positive integer.'

if (( prune_only == 1 )); then
  [[ "${BACKUP_NAMESPACE:-}" == "$namespace" ]] || fail 'BACKUP_NAMESPACE must match --namespace for retention cleanup.'
  if (( dry_run == 1 )); then
    printf 'DRY RUN: retention namespace=%s daily=%s weekly=%s\n' "$namespace" "$retention_daily" "$retention_weekly"
    exit 0
  fi
elif [[ "${BACKUP_NAMESPACE:-}" != "$namespace" ]]; then
  fail 'BACKUP_NAMESPACE must match --namespace.'
fi

prune_category() {
  local category="$1"
  local retention="$2"
  local category_root="${BACKUP_ROOT%/}/${category}"
  local -a backup_names=()
  local name path manifest_namespace
  local retained=0

  [[ -d "$category_root" ]] || return 0
  mapfile -t backup_names < <(find "$category_root" -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/20[0-9]{6}T[0-9]{6}Z' -printf '%f\n' | sort -r)

  for name in "${backup_names[@]}"; do
    path="${category_root}/${name}"
    [[ -f "${path}/manifest.json" ]] || continue
    manifest_namespace="$(jq -er '.namespace' "${path}/manifest.json" 2>/dev/null || true)"
    [[ "$manifest_namespace" == "$namespace" ]] || continue
    retained=$((retained + 1))
    (( retained <= retention )) && continue
    rm -rf -- "$path"
  done
}

if (( prune_only == 1 )); then
  prune_category daily "$retention_daily"
  prune_category weekly "$retention_weekly"
  printf 'Retention applied for namespace %s.\n' "$namespace"
  exit 0
fi

require_env AGE_RECIPIENT
require_env ELASTICSEARCH_SNAPSHOT_PATH
validate_snapshot_path "$ELASTICSEARCH_SNAPSHOT_PATH"

if (( dry_run == 1 )); then
  printf 'DRY RUN: namespace=%s kind=%s postgres=%s mongo=%s elasticsearch=%s\n' \
    "$namespace" "$backup_kind" "$PGDATABASE" "$mongo_database" "$index_pattern"
  exit 0
fi

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "Required command is missing: $1."
}

for command_name in pg_dump mongodump age curl jq tar sha256sum; do
  require_cmd "$command_name"
done

backup_id="$(date -u '+%Y%m%dT%H%M%SZ')"
backup_parent="${BACKUP_ROOT%/}/${backup_kind}"
backup_dir="${backup_parent}/${backup_id}"
snapshot_repository="javmeta_${namespace}_${backup_id//[^a-zA-Z0-9]/_}"
snapshot_name="snapshot_${backup_id}"
snapshot_path="${ELASTICSEARCH_SNAPSHOT_PATH%/}/${snapshot_repository}"
repo_registered=0
backup_created=0
snapshot_path_created=0
latest_tmp=''

cleanup() {
  local status="$1"

  if (( repo_registered == 1 )); then
    elasticsearch_request DELETE "/_snapshot/${snapshot_repository}" >/dev/null 2>&1 || true
  fi
  if [[ -n "$snapshot_path" && "$snapshot_path" == "${ELASTICSEARCH_SNAPSHOT_PATH%/}/"* && -d "$snapshot_path" ]] && (( snapshot_path_created == 1 )); then
    rm -rf -- "$snapshot_path"
  fi
  if (( status != 0 && backup_created == 1 )) && [[ -d "$backup_dir" ]]; then
    rm -rf -- "$backup_dir"
  fi
  if [[ -n "$latest_tmp" ]]; then
    rm -f -- "$latest_tmp"
  fi
}
trap 'status=$?; cleanup "$status"; exit "$status"' EXIT

[[ ! -e "$backup_dir" ]] || fail 'A backup folder with the generated timestamp already exists.'
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
backup_created=1
mkdir -p "$ELASTICSEARCH_SNAPSHOT_PATH"

pg_dump_args=(--format=custom --no-password --no-owner --no-privileges --dbname="$PGDATABASE")
if [[ -n "${PG_DUMP_SCHEMA:-}" ]]; then
  [[ "$PG_DUMP_SCHEMA" == "$namespace"* && "$PG_DUMP_SCHEMA" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'PG_DUMP_SCHEMA must stay inside the explicitly named namespace.'
  pg_dump_args+=(--schema="$PG_DUMP_SCHEMA")
fi
PGPASSWORD="$PGPASSWORD" pg_dump "${pg_dump_args[@]}" | age --encrypt --recipient "$AGE_RECIPIENT" --output "${backup_dir}/postgres.dump.age"
printf 'PostgreSQL dump encrypted.\n'

mongodump_args=(--uri="$MONGO_URI" --archive --gzip --quiet)
if [[ -n "${MONGO_COLLECTION:-}" ]]; then
  [[ "$MONGO_COLLECTION" == "$namespace"* && "$MONGO_COLLECTION" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'MONGO_COLLECTION must stay inside the explicitly named namespace.'
  mongodump_args+=(--collection="$MONGO_COLLECTION")
fi
mongodump "${mongodump_args[@]}" | age --encrypt --recipient "$AGE_RECIPIENT" --output "${backup_dir}/mongo.archive.age"
printf 'MongoDB archive encrypted.\n'

[[ ! -e "$snapshot_path" ]] || fail 'The generated Elasticsearch snapshot path already exists.'
mkdir -p "$snapshot_path"
snapshot_path_created=1
snapshot_repository_body="$(jq -cn --arg location "$snapshot_path" '{type:"fs",settings:{location:$location,compress:true}}')"
snapshot_registered="$(elasticsearch_request PUT "/_snapshot/${snapshot_repository}" "$snapshot_repository_body" 2>/dev/null)" \
  || fail 'Elasticsearch could not register the filesystem repository; verify path.repo and the shared snapshot mount.'
jq -e '.acknowledged == true' >/dev/null <<<"$snapshot_registered" \
  || fail 'Elasticsearch did not acknowledge the filesystem repository registration.'
repo_registered=1

snapshot_result="$(elasticsearch_request PUT "/_snapshot/${snapshot_repository}/${snapshot_name}?wait_for_completion=true" "{\"indices\":\"$index_pattern\",\"include_global_state\":false,\"ignore_unavailable\":false}" 2>/dev/null)" \
  || fail 'Elasticsearch snapshot failed; verify the explicitly named index namespace and repository permissions.'
jq -e '.snapshot.state == "SUCCESS" and (.snapshot.failures | length) == 0' >/dev/null <<<"$snapshot_result" \
  || fail 'Elasticsearch snapshot did not finish successfully.'

find "$snapshot_path" -mindepth 1 -print -quit | grep -q . \
  || fail 'Elasticsearch returned success but created no filesystem snapshot files.'
tar -C "$snapshot_path" -czf - . | age --encrypt --recipient "$AGE_RECIPIENT" --output "${backup_dir}/elasticsearch.snapshot.tar.gz.age"
elasticsearch_request DELETE "/_snapshot/${snapshot_repository}" >/dev/null 2>&1 \
  || fail 'Elasticsearch snapshot completed but its temporary repository could not be unregistered.'
repo_registered=0
rm -rf -- "$snapshot_path"
snapshot_path_created=0
printf 'Elasticsearch snapshot encrypted.\n'

created_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
jq -n \
  --arg namespace "$namespace" \
  --arg kind "$backup_kind" \
  --arg created_at "$created_at" \
  --arg pg_database "$PGDATABASE" \
  --arg pg_schema "${PG_DUMP_SCHEMA:-}" \
  --arg mongo_database "$mongo_database" \
  --arg mongo_collection "${MONGO_COLLECTION:-}" \
  --arg es_indices "$index_pattern" \
  --arg es_snapshot "$snapshot_name" \
  '{namespace:$namespace,kind:$kind,created_at:$created_at,targets:{postgres:{database:$pg_database,schema:$pg_schema},mongodb:{database:$mongo_database,collection:$mongo_collection},elasticsearch:{indices:$es_indices,snapshot:$es_snapshot}},artifacts:["postgres.dump.age","mongo.archive.age","elasticsearch.snapshot.tar.gz.age"]}' \
  > "${backup_dir}/manifest.json"
(cd "$backup_dir" && sha256sum postgres.dump.age mongo.archive.age elasticsearch.snapshot.tar.gz.age > manifest.sha256)
chmod 600 "$backup_dir"/manifest.json "$backup_dir"/manifest.sha256 "$backup_dir"/*.age

prune_category daily "$retention_daily"
prune_category weekly "$retention_weekly"

if [[ "${BACKUP_PUBLISH_LATEST:-1}" == '1' ]]; then
  latest_tmp="${BACKUP_ROOT%/}/.latest-${backup_id}.tmp"
  mkdir -p "$BACKUP_ROOT"
  cp "${backup_dir}/manifest.json" "$latest_tmp"
  chmod 600 "$latest_tmp"
  mv -f -- "$latest_tmp" "${BACKUP_ROOT%/}/latest.json"
  latest_tmp=''
fi

backup_created=0
printf 'Backup created: %s\n' "$backup_dir"

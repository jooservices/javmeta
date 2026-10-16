#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
# shellcheck source=deploy/backup/common.sh
source "${SCRIPT_DIR}/common.sh"

usage() {
  cat <<'USAGE'
Usage: restore.sh --backup-dir=<directory> --namespace=<name> [--force-namespace=<name>] [--dry-run]

AGE_IDENTITY, PGHOST, PGPORT, PGUSER, PGPASSWORD, PGDATABASE, MONGO_URI,
ELASTICSEARCH_HOST, and ELASTICSEARCH_SNAPSHOT_PATH are required.
USAGE
}

backup_dir=''
namespace=''
force_namespace=''
dry_run=0

for argument in "$@"; do
  case "$argument" in
    --backup-dir=*) backup_dir="${argument#*=}" ;;
    --namespace=*) namespace="${argument#*=}" ;;
    --force-namespace=*) force_namespace="${argument#*=}" ;;
    --dry-run) dry_run=1 ;;
    --help|-h) usage; exit 0 ;;
    *) fail "Unknown argument: ${argument}" ;;
  esac
done

[[ -n "$backup_dir" && -n "$namespace" ]] || { usage >&2; fail 'Explicit --backup-dir and --namespace arguments are required.'; }
validate_namespace "$namespace"
[[ -z "$force_namespace" || "$force_namespace" == "$namespace" ]] || fail '--force-namespace must exactly match --namespace.'

require_env BACKUP_ROOT
require_env AGE_IDENTITY
require_env PGHOST
require_env PGPORT
require_env PGUSER
require_env PGPASSWORD
require_env PGDATABASE
require_env MONGO_URI
require_env ELASTICSEARCH_HOST
require_env ELASTICSEARCH_SNAPSHOT_PATH
validate_snapshot_path "$ELASTICSEARCH_SNAPSHOT_PATH"

mongo_database="$(mongo_database_from_uri "$MONGO_URI")"
[[ -n "$mongo_database" ]] || fail 'MONGO_URI must name its database in the path.'
resolved_backup_dir="$(realpath -e "$backup_dir")" || fail 'Backup directory does not exist.'
resolved_backup_root="$(realpath -m "$BACKUP_ROOT")"
case "$resolved_backup_dir/" in
  "${resolved_backup_root%/}/daily/"*|"${resolved_backup_root%/}/weekly/"*) ;;
  *) fail 'Backup directory must be inside BACKUP_ROOT/daily or BACKUP_ROOT/weekly.' ;;
esac

manifest="${resolved_backup_dir}/manifest.json"
checksum_manifest="${resolved_backup_dir}/manifest.sha256"
[[ -f "$manifest" && -f "$checksum_manifest" ]] || fail 'Backup manifest or checksum file is missing.'

manifest_namespace="$(jq -er '.namespace' "$manifest")" || fail 'Backup manifest namespace is invalid.'
[[ "$manifest_namespace" == "$namespace" ]] || fail 'Backup manifest namespace does not match --namespace.'
pg_database="$(jq -er '.targets.postgres.database' "$manifest")" || fail 'PostgreSQL target is missing from the manifest.'
pg_schema="$(jq -er '.targets.postgres.schema // empty' "$manifest")"
manifest_mongo_database="$(jq -er '.targets.mongodb.database' "$manifest")" || fail 'MongoDB target is missing from the manifest.'
mongo_collection="$(jq -er '.targets.mongodb.collection // empty' "$manifest")"
index_pattern="$(jq -er '.targets.elasticsearch.indices' "$manifest")" || fail 'Elasticsearch index pattern is missing from the manifest.'
snapshot_name="$(jq -er '.targets.elasticsearch.snapshot' "$manifest")" || fail 'Elasticsearch snapshot name is missing from the manifest.'

[[ "$pg_database" == "$PGDATABASE" ]] || fail 'PGDATABASE must match the explicitly named restore target.'
[[ "$manifest_mongo_database" == "$mongo_database" ]] || fail 'MONGO_URI database must match the backup namespace.'
validate_namespace_targets "$namespace" "$mongo_database" "$index_pattern"
if [[ -n "$pg_schema" ]]; then
  [[ "$pg_schema" == "$namespace"* && "$pg_schema" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'Backup manifest PostgreSQL schema leaves the explicitly named namespace.'
fi
if [[ -n "$mongo_collection" ]]; then
  [[ "$mongo_collection" == "$namespace"* && "$mongo_collection" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'Backup manifest MongoDB collection leaves the explicitly named namespace.'
fi
[[ "$snapshot_name" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'Backup manifest Elasticsearch snapshot name is invalid.'

for artifact in postgres.dump.age mongo.archive.age elasticsearch.snapshot.tar.gz.age; do
  [[ -f "${resolved_backup_dir}/${artifact}" ]] || fail "Backup artifact is missing: ${artifact}."
done
awk '
  BEGIN {
    expected["postgres.dump.age"] = 1
    expected["mongo.archive.age"] = 1
    expected["elasticsearch.snapshot.tar.gz.age"] = 1
  }
  NF != 2 || length($1) != 64 || $1 ~ /[^[:xdigit:]]/ || !($2 in expected) || seen[$2]++ { exit 1 }
  { count++ }
  END { if (count != 3) exit 1 }
' "$checksum_manifest" || fail 'Backup checksum manifest is invalid.'
(cd "$resolved_backup_dir" && sha256sum --check --status manifest.sha256) \
  || fail 'Backup artifact checksum verification failed.'

if (( dry_run == 1 )); then
  printf 'DRY RUN: namespace=%s postgres=%s mongo=%s elasticsearch=%s; manifest checksums verified.\n' \
    "$namespace" "$pg_database" "$mongo_database" "$index_pattern"
  exit 0
fi

[[ -f "$AGE_IDENTITY" && -r "$AGE_IDENTITY" ]] || fail 'AGE_IDENTITY must name a readable age identity file.'
for command_name in age pg_restore psql mongorestore mongosh curl jq tar sha256sum; do
  command -v "$command_name" >/dev/null 2>&1 || fail "Required command is missing: ${command_name}."
done

pg_objects="$(postgres_user_object_count)" || fail 'Could not inspect the PostgreSQL restore target.'
[[ "$pg_objects" =~ ^[0-9]+$ ]] || fail 'PostgreSQL target inspection returned an invalid object count.'
mongo_collections="$(mongo_collection_count)" || fail 'Could not inspect the MongoDB restore target.'
[[ "$mongo_collections" =~ ^[0-9]+$ ]] || fail 'MongoDB target inspection returned an invalid collection count.'
es_indices="$(elasticsearch_index_count "$index_pattern")" || fail 'Could not inspect the Elasticsearch restore target.'
[[ "$es_indices" =~ ^[0-9]+$ ]] || fail 'Elasticsearch target inspection returned an invalid index count.'

if (( pg_objects > 0 || mongo_collections > 0 || es_indices > 0 )); then
  [[ "$force_namespace" == "$namespace" ]] || fail 'Restore target is not empty; repeat only with --force-namespace=<same namespace>.'
fi

temporary_dir="$(mktemp -d)"
chmod 700 "$temporary_dir"
snapshot_repository="javmeta_restore_${snapshot_name}"
snapshot_path="${ELASTICSEARCH_SNAPSHOT_PATH%/}/${snapshot_repository}"
repo_registered=0
snapshot_path_created=0

cleanup() {
  local status="$1"

  if (( repo_registered == 1 )); then
    elasticsearch_request DELETE "/_snapshot/${snapshot_repository}" >/dev/null 2>&1 || true
  fi
  if [[ -n "$snapshot_path" && "$snapshot_path" == "${ELASTICSEARCH_SNAPSHOT_PATH%/}/"* && -d "$snapshot_path" ]] && (( snapshot_path_created == 1 )); then
    rm -rf -- "$snapshot_path"
  fi
  if [[ -n "${temporary_dir:-}" && -d "$temporary_dir" ]]; then
    rm -rf -- "$temporary_dir"
  fi
  return "$status"
}
trap 'status=$?; cleanup "$status"; exit "$status"' EXIT

age --decrypt --identity "$AGE_IDENTITY" --output "${temporary_dir}/postgres.dump" "${resolved_backup_dir}/postgres.dump.age"
age --decrypt --identity "$AGE_IDENTITY" --output "${temporary_dir}/mongo.archive" "${resolved_backup_dir}/mongo.archive.age"
age --decrypt --identity "$AGE_IDENTITY" --output "${temporary_dir}/elasticsearch.snapshot.tar.gz" "${resolved_backup_dir}/elasticsearch.snapshot.tar.gz.age"

pg_restore_args=(--exit-on-error --no-owner --no-privileges --dbname="$PGDATABASE")
[[ -z "$pg_schema" ]] || pg_restore_args+=(--schema="$pg_schema")
if (( pg_objects > 0 )); then
  pg_restore_args+=(--clean --if-exists)
fi
PGPASSWORD="$PGPASSWORD" pg_restore "${pg_restore_args[@]}" "${temporary_dir}/postgres.dump"

mongo_ns_include="${mongo_database}.*"
if [[ -n "$mongo_collection" ]]; then
  mongo_ns_include="${mongo_database}.${mongo_collection}"
fi
mongo_restore_args=(--uri="$MONGO_URI" --archive="${temporary_dir}/mongo.archive" --gzip --nsInclude "$mongo_ns_include")
if (( mongo_collections > 0 )); then
  mongo_restore_args+=(--drop)
fi
mongorestore "${mongo_restore_args[@]}" >/dev/null

[[ ! -e "$snapshot_path" ]] || fail 'The generated Elasticsearch restore path already exists.'
mkdir -p "$snapshot_path"
snapshot_path_created=1
tar -xzf "${temporary_dir}/elasticsearch.snapshot.tar.gz" -C "$snapshot_path"
snapshot_repository_body="$(jq -cn --arg location "$snapshot_path" '{type:"fs",settings:{location:$location,readonly:true}}')"
snapshot_registered_response="$(elasticsearch_request PUT "/_snapshot/${snapshot_repository}" "$snapshot_repository_body" 2>/dev/null)" \
  || fail 'Elasticsearch could not read the filesystem snapshot repository; verify path.repo and the shared snapshot mount.'
jq -e '.acknowledged == true' >/dev/null <<<"$snapshot_registered_response" \
  || fail 'Elasticsearch did not acknowledge the restore repository registration.'
repo_registered=1

if (( es_indices > 0 )); then
  elasticsearch_request DELETE "/${index_pattern}?ignore_unavailable=true&allow_no_indices=true" >/dev/null \
    || fail 'Elasticsearch could not clear the explicitly named restore index namespace.'
fi
restore_body="$(jq -cn --arg indices "$index_pattern" '{indices:$indices,include_global_state:false,ignore_unavailable:false}')"
restore_response="$(elasticsearch_request POST "/_snapshot/${snapshot_repository}/${snapshot_name}/_restore?wait_for_completion=true" "$restore_body" 2>/dev/null)" \
  || fail 'Elasticsearch restore failed for the explicitly named index namespace.'
jq -e '.snapshot.state == "SUCCESS" or .accepted == true' >/dev/null <<<"$restore_response" \
  || fail 'Elasticsearch did not acknowledge successful snapshot restoration.'

elasticsearch_request DELETE "/_snapshot/${snapshot_repository}" >/dev/null 2>&1 \
  || fail 'Elasticsearch snapshot was restored but its temporary repository could not be unregistered.'
repo_registered=0
rm -rf -- "$snapshot_path"
snapshot_path_created=0
printf 'Restore completed for namespace %s.\n' "$namespace"

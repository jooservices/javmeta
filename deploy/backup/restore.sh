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
ELASTICSEARCH_HOST, and ELASTICSEARCH_SNAPSHOT_PATH are required for manifests
that include Elasticsearch.
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
manifest_has_components="$(jq -r 'has("components")' "$manifest")"
if [[ "$manifest_has_components" == 'true' ]]; then
  jq -e '(.components == ["postgres","mongodb"]) or (.components == ["postgres","mongodb","elasticsearch"])' "$manifest" >/dev/null \
    || fail 'Backup manifest components are invalid.'
  if jq -e '.components == ["postgres","mongodb","elasticsearch"]' "$manifest" >/dev/null; then
    include_elasticsearch=1
  else
    include_elasticsearch=0
  fi
else
  # Manifests created before the components field always included Elasticsearch.
  include_elasticsearch=1
fi

index_pattern=''
snapshot_name=''
if (( include_elasticsearch == 1 )); then
  index_pattern="$(jq -er '.targets.elasticsearch.indices' "$manifest")" || fail 'Elasticsearch index pattern is missing from the manifest.'
  snapshot_name="$(jq -er '.targets.elasticsearch.snapshot' "$manifest")" || fail 'Elasticsearch snapshot name is missing from the manifest.'
  expected_artifacts='["postgres.dump.age","mongo.archive.age","elasticsearch.snapshot.tar.gz.age"]'
else
  expected_artifacts='["postgres.dump.age","mongo.archive.age"]'
fi
jq -e --argjson expected "$expected_artifacts" '.artifacts == $expected' "$manifest" >/dev/null \
  || fail 'Backup manifest artifact list does not match its components.'

[[ "$pg_database" == "$PGDATABASE" ]] || fail 'PGDATABASE must match the explicitly named restore target.'
[[ "$manifest_mongo_database" == "$mongo_database" ]] || fail 'MONGO_URI database must match the backup namespace.'
if (( include_elasticsearch == 1 )); then
  validate_namespace_targets "$namespace" "$mongo_database" "$index_pattern"
else
  validate_database_namespace_targets "$namespace" "$mongo_database"
fi
if [[ -n "$pg_schema" ]]; then
  [[ "$pg_schema" == "$namespace"* && "$pg_schema" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'Backup manifest PostgreSQL schema leaves the explicitly named namespace.'
fi
if [[ -n "$mongo_collection" ]]; then
  [[ "$mongo_collection" == "$namespace"* && "$mongo_collection" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'Backup manifest MongoDB collection leaves the explicitly named namespace.'
fi
if (( include_elasticsearch == 1 )); then
  [[ "$snapshot_name" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'Backup manifest Elasticsearch snapshot name is invalid.'
fi

artifacts=(postgres.dump.age mongo.archive.age)
if (( include_elasticsearch == 1 )); then
  artifacts+=(elasticsearch.snapshot.tar.gz.age)
fi
for artifact in "${artifacts[@]}"; do
  [[ -f "${resolved_backup_dir}/${artifact}" ]] || fail "Backup artifact is missing: ${artifact}."
done
awk -v include_elasticsearch="$include_elasticsearch" '
  BEGIN {
    expected["postgres.dump.age"] = 1
    expected["mongo.archive.age"] = 1
    expected_count = 2
    if (include_elasticsearch == 1) {
      expected["elasticsearch.snapshot.tar.gz.age"] = 1
      expected_count = 3
    }
  }
  NF != 2 || length($1) != 64 || $1 ~ /[^[:xdigit:]]/ || !($2 in expected) || seen[$2]++ { exit 1 }
  { count++ }
  END { if (count != expected_count) exit 1 }
' "$checksum_manifest" || fail 'Backup checksum manifest is invalid.'
(cd "$resolved_backup_dir" && sha256sum --check --status manifest.sha256) \
  || fail 'Backup artifact checksum verification failed.'

if (( include_elasticsearch == 1 )); then
  require_env ELASTICSEARCH_HOST
  require_env ELASTICSEARCH_SNAPSHOT_PATH
  validate_snapshot_path "$ELASTICSEARCH_SNAPSHOT_PATH"
fi

if (( dry_run == 1 )); then
  if (( include_elasticsearch == 1 )); then
    printf 'DRY RUN: namespace=%s postgres=%s mongo=%s elasticsearch=%s; manifest checksums verified.\n' \
      "$namespace" "$pg_database" "$mongo_database" "$index_pattern"
  else
    printf 'DRY RUN: namespace=%s postgres=%s mongo=%s elasticsearch=not-included; manifest checksums verified.\n' \
      "$namespace" "$pg_database" "$mongo_database"
  fi
  exit 0
fi

[[ -f "$AGE_IDENTITY" && -r "$AGE_IDENTITY" ]] || fail 'AGE_IDENTITY must name a readable age identity file.'
required_commands=(age pg_restore psql mongorestore mongosh jq sha256sum)
if (( include_elasticsearch == 1 )); then
  required_commands+=(curl tar)
fi
for command_name in "${required_commands[@]}"; do
  command -v "$command_name" >/dev/null 2>&1 || fail "Required command is missing: ${command_name}."
done
if (( include_elasticsearch == 1 )); then
  require_elasticsearch_snapshot_path_repo "$ELASTICSEARCH_SNAPSHOT_PATH"
fi

pg_objects="$(postgres_user_object_count)" || fail 'Could not inspect the PostgreSQL restore target.'
[[ "$pg_objects" =~ ^[0-9]+$ ]] || fail 'PostgreSQL target inspection returned an invalid object count.'
mongo_collections="$(mongo_collection_count)" || fail 'Could not inspect the MongoDB restore target.'
[[ "$mongo_collections" =~ ^[0-9]+$ ]] || fail 'MongoDB target inspection returned an invalid collection count.'
es_indices=0
if (( include_elasticsearch == 1 )); then
  es_indices="$(elasticsearch_index_count "$index_pattern")" || fail 'Could not inspect the Elasticsearch restore target.'
  [[ "$es_indices" =~ ^[0-9]+$ ]] || fail 'Elasticsearch target inspection returned an invalid index count.'
fi

if (( pg_objects > 0 || mongo_collections > 0 || es_indices > 0 )); then
  [[ "$force_namespace" == "$namespace" ]] || fail 'Restore target is not empty; repeat only with --force-namespace=<same namespace>.'
fi

temporary_dir="$(mktemp -d)"
chmod 700 "$temporary_dir"
snapshot_repository=''
snapshot_path=''
if (( include_elasticsearch == 1 )); then
  snapshot_repository="javmeta_restore_${snapshot_name}"
  snapshot_path="${ELASTICSEARCH_SNAPSHOT_PATH%/}/${snapshot_repository}"
fi
repo_registered=0
snapshot_path_created=0

cleanup() {
  local status="$1"

  if (( repo_registered == 1 )); then
    elasticsearch_request DELETE "/_snapshot/${snapshot_repository}" >/dev/null 2>&1 || true
  fi
  if (( include_elasticsearch == 1 && snapshot_path_created == 1 )) \
    && [[ -n "$snapshot_path" && "$snapshot_path" == "${ELASTICSEARCH_SNAPSHOT_PATH%/}/"* && -d "$snapshot_path" ]]; then
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
if (( include_elasticsearch == 1 )); then
  age --decrypt --identity "$AGE_IDENTITY" --output "${temporary_dir}/elasticsearch.snapshot.tar.gz" "${resolved_backup_dir}/elasticsearch.snapshot.tar.gz.age"
fi

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

if (( include_elasticsearch == 1 )); then
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
else
  printf 'Restore completed for namespace %s (PostgreSQL and MongoDB; Elasticsearch not included).\n' "$namespace"
fi

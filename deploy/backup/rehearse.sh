#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
# shellcheck source=deploy/backup/common.sh
source "${SCRIPT_DIR}/common.sh"

namespace=''
for argument in "$@"; do
  case "$argument" in
    --namespace=*) namespace="${argument#*=}" ;;
    --help|-h) printf 'Usage: rehearse.sh --namespace=javmeta_it\n'; exit 0 ;;
    *) fail "Unknown argument: ${argument}" ;;
  esac
done

[[ "$namespace" == javmeta_it* ]] || fail 'Rehearsal requires an explicit javmeta_it* namespace.'
validate_namespace "$namespace"
for name in BACKUP_ROOT BACKUP_NAMESPACE AGE_RECIPIENT AGE_IDENTITY PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE MONGO_URI ELASTICSEARCH_HOST ELASTICSEARCH_SNAPSHOT_PATH; do
  require_env "$name"
done
[[ "$BACKUP_NAMESPACE" == "$namespace" ]] || fail 'BACKUP_NAMESPACE must match --namespace.'

mongo_database="$(mongo_database_from_uri "$MONGO_URI")"
[[ -n "$mongo_database" ]] || fail 'MONGO_URI must name its database in the path.'
validate_namespace_targets "$namespace" "$mongo_database" "${namespace}*"
validate_snapshot_path "$ELASTICSEARCH_SNAPSHOT_PATH"
for command_name in psql mongosh jq curl age; do
  command -v "$command_name" >/dev/null 2>&1 || fail "Required command is missing: $command_name."
done

run_id="$(date -u '+%Y%m%dT%H%M%S%NZ')_${RANDOM}"
fixture="${namespace}_backup_${run_id}"
pg_schema="$fixture"
mongo_collection="$fixture"
es_index="$fixture"
temporary_dir="$(mktemp -d)"
backup_dir=''
pg_created=0
mongo_created=0
es_created=0
current_phase=''
phase_start=0
overall='PASS'
printed=0

pg_status='NOT RUN'; mongo_status='NOT RUN'; es_status='NOT RUN'
backup_status='NOT RUN'; restore_status='NOT RUN'
pg_restore_status='NOT RUN'; mongo_restore_status='NOT RUN'; es_restore_status='NOT RUN'
pg_ms=0; mongo_ms=0; es_ms=0; backup_ms=0; restore_ms=0
pg_restore_ms=0; mongo_restore_ms=0; es_restore_ms=0
pg_counts='-'; mongo_counts='-'; es_counts='-'

begin_phase() { current_phase="$1"; phase_start="$(date -u '+%s%3N')"; }
elapsed_ms() { printf '%s' "$(( $(date -u '+%s%3N') - phase_start ))"; }

print_table() {
  printf '\n%-24s %-8s %10s %s\n' 'Check' 'Result' 'Time (ms)' 'Counts / details'
  printf '%-24s %-8s %10s %s\n' 'PostgreSQL seed' "$pg_status" "$pg_ms" "$pg_counts"
  printf '%-24s %-8s %10s %s\n' 'MongoDB seed' "$mongo_status" "$mongo_ms" "$mongo_counts"
  printf '%-24s %-8s %10s %s\n' 'Elasticsearch seed' "$es_status" "$es_ms" "$es_counts"
  printf '%-24s %-8s %10s %s\n' 'Encrypted backup' "$backup_status" "$backup_ms" '-'
  printf '%-24s %-8s %10s %s\n' 'Restore' "$restore_status" "$restore_ms" '-'
  printf '%-24s %-8s %10s %s\n' 'PostgreSQL restore' "$pg_restore_status" "$pg_restore_ms" "$pg_counts"
  printf '%-24s %-8s %10s %s\n' 'MongoDB restore' "$mongo_restore_status" "$mongo_restore_ms" "$mongo_counts"
  printf '%-24s %-8s %10s %s\n' 'Elasticsearch restore' "$es_restore_status" "$es_restore_ms" "$es_counts"
  printf '%-24s %-8s %10s %s\n' 'Overall rehearsal' "$overall" '-' '-'
  printed=1
}

cleanup() {
  local result="$1"
  local failed=0
  local backup_root_resolved backup_dir_resolved
  if (( pg_created )); then
    PGPASSWORD="$PGPASSWORD" psql --no-password --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$PGDATABASE" --set=ON_ERROR_STOP=1 --command="DROP SCHEMA IF EXISTS \"$pg_schema\" CASCADE;" >/dev/null 2>&1 || failed=1
  fi
  if (( mongo_created )); then
    mongosh --quiet "$MONGO_URI" --eval="db.dropCollection('$mongo_collection')" >/dev/null 2>&1 || failed=1
  fi
  if (( es_created )); then
    elasticsearch_request DELETE "/$es_index?ignore_unavailable=true&allow_no_indices=true" >/dev/null 2>&1 || failed=1
  fi
  if [[ -n "$backup_dir" && -d "$backup_dir" ]]; then
    backup_root_resolved="$(realpath -m "$BACKUP_ROOT")"
    backup_dir_resolved="$(realpath -m "$backup_dir")"
    case "$backup_dir_resolved/" in
      "${backup_root_resolved%/}/daily/"*|"${backup_root_resolved%/}/weekly/"*) rm -rf -- "$backup_dir_resolved" || failed=1 ;;
      *) failed=1 ;;
    esac
  fi
  rm -rf -- "$temporary_dir" || failed=1
  if (( failed )); then
    overall='FAIL'
    printf 'ERROR: Cleanup of one or more task-owned javmeta_it rehearsal fixtures failed.\n' >&2
    result=1
  fi
  if (( result != 0 )); then
    overall='FAIL'
    case "$current_phase" in
      'PostgreSQL seed') pg_status='FAIL' ;;
      'MongoDB seed') mongo_status='FAIL' ;;
      'Elasticsearch seed') es_status='FAIL' ;;
      'Encrypted backup') backup_status='FAIL' ;;
      'Restore') restore_status='FAIL' ;;
      'PostgreSQL restore') pg_restore_status='FAIL' ;;
      'MongoDB restore') mongo_restore_status='FAIL' ;;
      'Elasticsearch restore') es_restore_status='FAIL' ;;
    esac
  fi
  if (( printed == 0 )); then print_table; fi
  return "$result"
}
trap 'result=$?; cleanup "$result"; exit "$?"' EXIT

begin_phase 'PostgreSQL seed'
schema_exists="$(PGPASSWORD="$PGPASSWORD" psql --no-password --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$PGDATABASE" --tuples-only --no-align --set=ON_ERROR_STOP=1 --command="SELECT count(*) FROM information_schema.schemata WHERE schema_name = '$pg_schema';")"
[[ "$schema_exists" == '0' ]] || fail 'Generated PostgreSQL rehearsal schema already exists.'
pg_created=1
PGPASSWORD="$PGPASSWORD" psql --no-password --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$PGDATABASE" --set=ON_ERROR_STOP=1 --command="CREATE SCHEMA \"$pg_schema\"; CREATE TABLE \"$pg_schema\".fixture (id integer PRIMARY KEY, value text NOT NULL); INSERT INTO \"$pg_schema\".fixture VALUES (1, 'one'), (2, 'two'), (3, 'three');" >/dev/null
pg_counts="expected=3 actual=$(PGPASSWORD="$PGPASSWORD" psql --no-password --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$PGDATABASE" --tuples-only --no-align --set=ON_ERROR_STOP=1 --command="SELECT count(*) FROM \"$pg_schema\".fixture;")"
[[ "$pg_counts" == 'expected=3 actual=3' ]] || fail 'PostgreSQL seed count did not match.'
pg_ms="$(elapsed_ms)"; pg_status='PASS'

begin_phase 'MongoDB seed'
collection_exists="$(mongosh --quiet "$MONGO_URI" --eval="db.getCollectionNames().includes('$mongo_collection') ? 1 : 0")"
[[ "$collection_exists" == '0' ]] || fail 'Generated MongoDB rehearsal collection already exists.'
mongo_created=1
mongosh --quiet "$MONGO_URI" --eval="db.createCollection('$mongo_collection'); db.getCollection('$mongo_collection').insertMany([{_id:1,value:'one'},{_id:2,value:'two'},{_id:3,value:'three'}]);" >/dev/null
mongo_counts="expected=3 actual=$(mongosh --quiet "$MONGO_URI" --eval="db.getCollection('$mongo_collection').countDocuments()")"
[[ "$mongo_counts" == 'expected=3 actual=3' ]] || fail 'MongoDB seed count did not match.'
mongo_ms="$(elapsed_ms)"; mongo_status='PASS'

begin_phase 'Elasticsearch seed'
[[ "$(elasticsearch_index_count "$es_index")" == '0' ]] || fail 'Generated Elasticsearch rehearsal index already exists.'
es_created=1
elasticsearch_request PUT "/$es_index" '{"mappings":{"properties":{"value":{"type":"keyword"}}}}' >/dev/null
for document in one two three; do
  body="$(jq -cn --arg value "$document" '{value:$value}')"
  elasticsearch_request PUT "/$es_index/_doc/$document?refresh=true" "$body" >/dev/null
done
es_counts="expected=3 actual=$(jq -er '.count' <<<"$(elasticsearch_request GET "/$es_index/_count")")"
[[ "$es_counts" == 'expected=3 actual=3' ]] || fail 'Elasticsearch seed count did not match.'
es_ms="$(elapsed_ms)"; es_status='PASS'

begin_phase 'Encrypted backup'
backup_output="$(BACKUP_NAMESPACE="$namespace" BACKUP_PUBLISH_LATEST=0 PG_DUMP_SCHEMA="$pg_schema" MONGO_COLLECTION="$mongo_collection" ELASTICSEARCH_INDEX_PATTERN="$es_index" "$SCRIPT_DIR/backup.sh" --namespace="$namespace")"
backup_dir="$(sed -n 's/^Backup created: //p' <<<"$backup_output" | tail -n 1)"
[[ -n "$backup_dir" ]] || fail 'Backup script did not report its output directory.'
backup_ms="$(elapsed_ms)"; backup_status='PASS'

begin_phase 'Restore'
PG_DUMP_SCHEMA="$pg_schema" MONGO_COLLECTION="$mongo_collection" ELASTICSEARCH_INDEX_PATTERN="$es_index" "$SCRIPT_DIR/restore.sh" --backup-dir="$backup_dir" --namespace="$namespace" --force-namespace="$namespace" >/dev/null
restore_ms="$(elapsed_ms)"; restore_status='PASS'

begin_phase 'PostgreSQL restore'
pg_actual="$(PGPASSWORD="$PGPASSWORD" psql --no-password --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$PGDATABASE" --tuples-only --no-align --set=ON_ERROR_STOP=1 --command="SELECT count(*) FROM \"$pg_schema\".fixture;")"
pg_counts="expected=3 actual=$pg_actual"; [[ "$pg_actual" == '3' ]] || fail 'PostgreSQL restored row count did not match.'
pg_restore_ms="$(elapsed_ms)"; pg_restore_status='PASS'

begin_phase 'MongoDB restore'
mongo_actual="$(mongosh --quiet "$MONGO_URI" --eval="db.getCollection('$mongo_collection').countDocuments()")"
mongo_counts="expected=3 actual=$mongo_actual"; [[ "$mongo_actual" == '3' ]] || fail 'MongoDB restored document count did not match.'
mongo_restore_ms="$(elapsed_ms)"; mongo_restore_status='PASS'

begin_phase 'Elasticsearch restore'
es_actual="$(jq -er '.count' <<<"$(elasticsearch_request GET "/$es_index/_count")")"
es_counts="expected=3 actual=$es_actual"; [[ "$es_actual" == '3' ]] || fail 'Elasticsearch restored document count did not match.'
es_restore_ms="$(elapsed_ms)"; es_restore_status='PASS'

current_phase=''
printf 'PASS: fixture counts restored for PostgreSQL, MongoDB and Elasticsearch.\n'

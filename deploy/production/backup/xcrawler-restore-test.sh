#!/usr/bin/env bash
set +x
set -Eeuo pipefail
# shellcheck source=deploy/production/backup/common.sh
source "$(dirname -- "${BASH_SOURCE[0]}")/common.sh"
target=''
while (( $# )); do
  case "$1" in
    --env-file) env_file="${2:?Missing env file}"; shift 2 ;;
    --backup-dir) backup_dir="${2:?Missing backup dir}"; shift 2 ;;
    --manifest) manifest="${2:?Missing manifest path}"; shift 2 ;;
    --database) target="${2:?Missing target database}"; shift 2 ;;
    *) fail 'Unknown restore option.' ;;
  esac
done
load_config
[[ -d "$backup_dir" ]] || fail 'Backup directory does not exist.'
backup_dir="$(cd -- "$backup_dir" && pwd -P)"
[[ "$backup_dir" != / ]] || fail 'Backup directory resolves to root.'
restore_prefix="${PGDATABASE}_restore_"
[[ "$target" =~ ^[a-zA-Z_][a-zA-Z0-9_]{0,62}$ && "$target" == "$restore_prefix"* ]] || fail 'Target must be a simple database name prefixed with the app database and _restore_.'
exec 9> "$backup_dir/.xcrawler-backup.lock"
flock -n 9 || fail 'A backup or restore test is running.'
work="$(mktemp -d "$backup_dir/.xcrawler-restore.XXXXXXXX")"
trap 'rm -rf -- "$work"' EXIT
trap 'exit 1' HUP INT TERM
# The atomic success manifest identifies the latest run even for reruns in one second.
dump="$("$php_bin" "$backup_scripts/support.php" dump "$manifest" 2>/dev/null)"
bundle_dir="$(dirname -- "$dump")"
[[ "$(dirname -- "$bundle_dir")" == "$backup_dir" && "$(basename -- "$bundle_dir")" =~ ^xcrawler-backup-[0-9]{8}-[a-zA-Z0-9]{8}$ && "$(basename -- "$dump")" == postgres.dump && ! -L "$bundle_dir" && ! -L "$dump" && -f "$dump" ]] || fail 'No completed backup found.'
"$php_bin" "$backup_scripts/support.php" owns "$bundle_dir" || fail 'Unrecognized backup producer.'
pg_restore -l "$dump" > "$work/toc" 2>/dev/null
# Query the app connection only for database existence; all writes name the isolated target explicitly.
exists="$(psql -X -At --no-password --set=ON_ERROR_STOP=1 -c "SELECT count(*) FROM pg_database WHERE datname = '$target'" 2>/dev/null)"
if [[ "$exists" == 0 ]]; then
  createdb --no-password --maintenance-db="$PGDATABASE" -- "$target" 2>/dev/null
elif [[ "$exists" != 1 ]]; then
  fail 'Cannot determine target existence.'
fi
export PGDATABASE="$target"
# Refuse any user objects, not just populated tables. No --clean or database drops are ever used.
objects="$(psql -X -At --no-password --set=ON_ERROR_STOP=1 -c "SELECT (SELECT count(*) FROM pg_class WHERE relnamespace NOT IN (SELECT oid FROM pg_namespace WHERE nspname LIKE 'pg_%' OR nspname = 'information_schema')) + (SELECT count(*) FROM pg_proc WHERE pronamespace NOT IN (SELECT oid FROM pg_namespace WHERE nspname LIKE 'pg_%' OR nspname = 'information_schema'))" 2>/dev/null)"
[[ "$objects" == 0 ]] || fail 'Restore target is not empty.'
pg_restore --no-password --exit-on-error --no-owner --no-privileges --dbname="$target" "$dump" > /dev/null 2>&1
expected_tables="$(awk '$4 == "TABLE" && $5 != "DATA" && $5 != "ATTACH" {n++} END {print n+0}' "$work/toc")"
actual_tables="$(psql -X -At --no-password --set=ON_ERROR_STOP=1 -c "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.relkind IN ('r','p') AND n.nspname NOT LIKE 'pg_%' AND n.nspname <> 'information_schema'" 2>/dev/null)"
[[ "$actual_tables" == "$expected_tables" ]] || fail 'Restored table count differs from dump listing.'
pg_restore --data-only --file=- "$dump" 2>/dev/null | "$php_bin" "$backup_scripts/support.php" counts > "$work/counts.sql"
psql -X -At --no-password --set=ON_ERROR_STOP=1 --file="$work/counts.sql" > /dev/null 2>&1
printf 'Restore test passed: %s tables and COPY row counts verified in %s. Target retained for inspection.\n' "$actual_tables" "$target"

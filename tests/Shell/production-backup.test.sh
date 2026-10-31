#!/usr/bin/env bash
set -Eeuo pipefail

source_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
tmp_root="$source_root/.tmp"
tmp_root_created=0
if [[ ! -d "$tmp_root" ]]; then
  mkdir -p "$tmp_root"
  tmp_root_created=1
fi
test_dir="$(mktemp -d "$source_root/.tmp/production-backup-test.XXXXXX")"
cleanup() {
  if [[ "${KEEP_TEST_DIR:-0}" == 1 ]]; then
    printf 'Preserved production backup test directory: %s\n' "$test_dir" >&2
  else
    rm -rf -- "$test_dir"
  fi
  if (( tmp_root_created )); then rmdir "$tmp_root" 2>/dev/null || true; fi
}
trap cleanup EXIT

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

backup_script="$source_root/deploy/production/backup/xcrawler-backup.sh"
restore_script="$source_root/deploy/production/backup/xcrawler-restore-test.sh"
backup_directory="$test_dir/backup directory"
manifest="$test_dir/readiness/latest.json"
env_file="$test_dir/production.env"
bin_dir="$test_dir/bin"
calls="$test_dir/calls"
php_bin="${BACKUP_TEST_PHP_BIN:-$(command -v php)}"

[[ -n "$php_bin" ]] || fail 'PHP is required to exercise the production backup scripts.'
mkdir -p "$bin_dir" "$backup_directory" "$(dirname -- "$manifest")"
touch "$calls"
cat > "$env_file" <<ENV
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=xcrawler
DB_USERNAME=xcrawler
DB_PASSWORD=
DB_SSLMODE=require
MONGO_URI=mongodb://backup-test@127.0.0.1/xcrawler
MONGO_DATABASE=xcrawler
BACKUP_DIR="$backup_directory"
BACKUP_KEEP=3
JAVMETA_BACKUP_MANIFEST="$manifest"
ENV

cat > "$bin_dir/pg_dump" <<'STUB'
#!/usr/bin/env bash
printf 'pg_dump %s\n' "$*" >> "$TEST_CALLS"
[[ "${PGSSLMODE:-}" == require ]] || exit 90
[[ "${FAIL_PG_DUMP:-0}" != 1 ]] || { printf 'fake database diagnostic\n' >&2; exit 4; }
dump=''
for argument in "$@"; do
  [[ "$argument" == --file=* ]] && dump="${argument#--file=}"
done
[[ -n "$dump" ]] || exit 91
printf 'fake custom-format archive\n' > "$dump"
STUB

cat > "$bin_dir/pg_restore" <<'STUB'
#!/usr/bin/env bash
printf 'pg_restore %s\n' "$*" >> "$TEST_CALLS"
if [[ "$*" == *'--file=-'* ]]; then
  printf 'COPY public.users (id) FROM stdin;\n1\n2\n\\.\n'
elif [[ "$*" == *'--dbname='* ]]; then
  [[ "${FAIL_PG_RESTORE:-0}" != 1 ]] || exit 4
else
  printf '1; 1259 12345 TABLE public users app\n'
fi
STUB

cat > "$bin_dir/psql" <<'STUB'
#!/usr/bin/env bash
printf 'psql %s\n' "$*" >> "$TEST_CALLS"
if [[ "$*" == *'FROM pg_database'* ]]; then
  printf '%s\n' "${RESTORE_TARGET_EXISTS:-0}"
elif [[ "$*" == *'FROM pg_class WHERE relnamespace'* ]]; then
  printf '%s\n' "${RESTORE_OBJECTS:-0}"
elif [[ "$*" == *'FROM pg_class c JOIN pg_namespace'* ]]; then
  printf '%s\n' "${RESTORE_TABLES:-1}"
elif [[ "$*" == *'--file='* ]]; then
  counts_file=''
  for argument in "$@"; do
    [[ "$argument" == --file=* ]] && counts_file="${argument#--file=}"
  done
  grep -Fq 'count(*) FROM public.users) <> 2' "$counts_file" || exit 92
else
  exit 93
fi
STUB

cat > "$bin_dir/createdb" <<'STUB'
#!/usr/bin/env bash
printf 'createdb %s\n' "$*" >> "$TEST_CALLS"
STUB

cat > "$bin_dir/mongodump" <<'STUB'
#!/usr/bin/env bash
printf 'mongodump %s\n' "$*" >> "$TEST_CALLS"
config=''
archive=''
for argument in "$@"; do
  case "$argument" in
    --config=*) config="${argument#--config=}" ;;
    --archive=*) archive="${argument#--archive=}" ;;
    mongodb://*) exit 94 ;;
  esac
done
[[ -n "$config" && -n "$archive" ]] || exit 95
grep -Fq 'mongodb://backup-test@127.0.0.1/xcrawler' "$config" || exit 96
[[ "${FAIL_MONGODUMP:-0}" != 1 ]] || exit 4
printf 'fake Mongo archive\n' > "$archive"
STUB

chmod +x "$bin_dir"/*
export TEST_CALLS="$calls"
PATH="$bin_dir:$PATH"

# Shellcheck SC2329: this exported function is invoked by the child Bash script.
# shellcheck disable=SC2329
command() {
  if [[ "${1:-}" == -v && "${2:-}" == mongodump && "${DISABLE_MONGODUMP:-0}" == 1 ]]; then
    return 1
  fi
  builtin command "$@"
}
export -f command

run_backup() {
  BACKUP_ENV_FILE="$env_file" BACKUP_PHP_BIN="$php_bin" \
    PATH="$bin_dir:$PATH" DISABLE_MONGODUMP="${DISABLE_MONGODUMP:-0}" \
    FAIL_PG_DUMP="${FAIL_PG_DUMP:-0}" FAIL_MONGODUMP="${FAIL_MONGODUMP:-0}" \
    bash "$backup_script"
}

run_restore() {
  BACKUP_ENV_FILE="$env_file" BACKUP_PHP_BIN="$php_bin" \
    PATH="$bin_dir:$PATH" RESTORE_TARGET_EXISTS="${RESTORE_TARGET_EXISTS:-0}" \
    RESTORE_OBJECTS="${RESTORE_OBJECTS:-0}" RESTORE_TABLES="${RESTORE_TABLES:-1}" \
    bash "$restore_script" --database "${1:?Target database required}"
}

DISABLE_MONGODUMP=1 run_backup > "$test_dir/backup.log" 2>&1 || { cat "$test_dir/backup.log" >&2; fail 'PostgreSQL backup failed without optional MongoDB tools.'; }
grep -q 'mongodump missing' "$test_dir/backup.log" || fail 'Missing MongoDB tooling was not reported.'
[[ -s "$manifest" ]] || fail 'Readiness manifest was not published.'
grep -Fq '"postgresql": "verified"' "$manifest" || fail 'Manifest does not mark PostgreSQL as verified.'
grep -Fq '"mongodb": "skipped (mongodump missing)"' "$manifest" || fail 'Manifest does not disclose skipped MongoDB backup.'
grep -Fq '"created_at": "' "$manifest" || fail 'Manifest has no readiness timestamp.'
grep -Fq 'pg_dump --no-password -Fc --file=' "$calls" || fail 'PostgreSQL dump did not use custom format without argv credentials.'

cp "$manifest" "$test_dir/manifest.before-failure.json"
: > "$calls"
if FAIL_PG_DUMP=1 DISABLE_MONGODUMP=1 run_backup > "$test_dir/backup-failure.log" 2>&1; then
  fail 'A PostgreSQL dump failure was accepted.'
fi
cmp -s "$test_dir/manifest.before-failure.json" "$manifest" || fail 'A failed backup replaced the last successful manifest.'
! grep -q 'fake database diagnostic' "$test_dir/backup-failure.log" || fail 'Database tool diagnostics were exposed.'

: > "$calls"
if FAIL_MONGODUMP=1 run_backup > "$test_dir/mongo-failure.log" 2>&1; then
  fail 'A failed available MongoDB dump was accepted.'
fi
cmp -s "$test_dir/manifest.before-failure.json" "$manifest" || fail 'A failed MongoDB dump replaced the last successful manifest.'
grep -q '^mongodump --config=' "$calls" || fail 'MongoDB dump did not use a private config file.'
! grep -q 'mongodb://backup-test' "$calls" || fail 'MongoDB URI appeared in process arguments.'

# Seed four old UTC days, a same-day old run, and an unrelated directory.
today="$(date -u +%Y%m%d)"
for age in 1 2 3 4; do
  old_day="$(date -u -d "$age days ago" +%Y%m%d)"
  old_bundle="$backup_directory/xcrawler-backup-${old_day}-old0000${age}"
  mkdir -p "$old_bundle"
  printf '{"producer":"xcrawler-node-backup-v1"}\n' > "$old_bundle/manifest.json"
done
same_day_bundle="$backup_directory/xcrawler-backup-${today}-old00000"
mkdir -p "$same_day_bundle"
printf '{"producer":"xcrawler-node-backup-v1"}\n' > "$same_day_bundle/manifest.json"
mkdir -p "$backup_directory/operator-files"
printf 'keep\n' > "$backup_directory/operator-files/keep.txt"

: > "$calls"
run_backup > "$test_dir/mongo-success.log" 2>&1 || { cat "$test_dir/mongo-success.log" >&2; fail 'Backup failed when MongoDB tools were available.'; }
grep -Fq '"mongodb": "completed"' "$manifest" || fail 'Available MongoDB dump was not recorded as completed.'
grep -q '^mongodump --config=' "$calls" || fail 'Available MongoDB tooling was not called.'
grep -Fq 'Backup completed; PostgreSQL verified; mongodb: completed.' "$test_dir/mongo-success.log" || fail 'Successful backup status was not reported.'
[[ -f "$backup_directory/operator-files/keep.txt" ]] || fail 'Retention removed an unrelated directory.'
[[ ! -e "$same_day_bundle" ]] || fail 'Retention kept a stale same-day bundle.'
for age in 3 4; do
  expired_day="$(date -u -d "$age days ago" +%Y%m%d)"
  expired_bundle="$(find "$backup_directory" -mindepth 1 -maxdepth 1 -type d -name "xcrawler-backup-${expired_day}-*" -print -quit)"
  [[ -z "$expired_bundle" ]] || fail "Retention kept expired backup day ${expired_day}."
done

declare -A retained_days=()
while IFS= read -r -d '' bundle_path; do
  bundle_name="$(basename -- "$bundle_path")"
  if [[ "$bundle_name" =~ ^xcrawler-backup-([0-9]{8})-[a-zA-Z0-9]{8}$ ]]; then
    retained_days["${BASH_REMATCH[1]}"]=1
  fi
done < <(find "$backup_directory" -mindepth 1 -maxdepth 1 -type d -name 'xcrawler-backup-*' -print0)
[[ "${#retained_days[@]}" == 3 ]] || fail "Retention kept ${#retained_days[@]} days instead of three."

: > "$calls"
if run_restore postgres > "$test_dir/maintenance-restore.log" 2>&1; then
  fail 'Restore test accepted the PostgreSQL maintenance database as its target.'
fi
[[ ! -s "$calls" ]] || fail 'Restore reached PostgreSQL for the maintenance database target.'

: > "$calls"
if run_restore xcrawler > "$test_dir/unsafe-restore.log" 2>&1; then
  fail 'Restore test accepted the application database as its target.'
fi
! grep -q '^pg_restore ' "$calls" || fail 'Restore reached PostgreSQL for the application database target.'

: > "$calls"
run_restore xcrawler_restore_rehearsal > "$test_dir/restore.log" 2>&1 || fail 'Isolated PostgreSQL restore rehearsal failed.'
grep -q '^createdb --no-password --maintenance-db=xcrawler -- xcrawler_restore_rehearsal$' "$calls" || fail 'Restore rehearsal did not create an absent isolated target from the app connection.'
grep -Fq -- '--exit-on-error --no-owner --no-privileges --dbname=xcrawler_restore_rehearsal' "$calls" || fail 'Restore did not use safe restore flags and the isolated target.'
grep -q 'COPY row counts verified' "$test_dir/restore.log" || fail 'Restore did not report successful row-count verification.'

: > "$calls"
if RESTORE_TARGET_EXISTS=1 RESTORE_OBJECTS=1 run_restore xcrawler_restore_rehearsal > "$test_dir/nonempty-restore.log" 2>&1; then
  fail 'Restore test accepted a nonempty rehearsal database.'
fi
! grep -q '^pg_restore .*--dbname=' "$calls" || fail 'Restore wrote to a nonempty rehearsal database.'

timer="$source_root/deploy/production/node/xcrawler-backup.timer"
service="$source_root/deploy/production/node/xcrawler-backup.service"
installer="$source_root/deploy/production/node/install-node.sh"
grep -Fq 'OnCalendar=*-*-* 02:00:00 UTC' "$timer" || fail 'Backup timer has no daily UTC schedule.'
grep -Fq 'Persistent=true' "$timer" || fail 'Backup timer does not catch up missed runs.'
grep -Fq 'User=xcrawler' "$service" || fail 'Backup service does not run as the application user.'
grep -Fq 'UMask=0077' "$service" || fail 'Backup service does not use a private umask.'
grep -Fq 'ExecStopPost=/usr/bin/php8.5 artisan javmeta:backup-status --lenient' "$service" || fail 'Backup service does not publish the existing backup status alert.'
grep -Fq 'xcrawler-backup.timer' "$installer" || fail 'Node installer does not install and enable the backup timer.'

printf 'OK (production backup, retention, manifest, restore and systemd scenarios)\n'

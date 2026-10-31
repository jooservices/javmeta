#!/usr/bin/env bash
set +x
set -Eeuo pipefail
# shellcheck source=deploy/production/backup/common.sh
source "$(dirname -- "${BASH_SOURCE[0]}")/common.sh"
while (( $# )); do
  case "$1" in
    --env-file) env_file="${2:?Missing env file}"; shift 2 ;;
    --backup-dir) backup_dir="${2:?Missing backup dir}"; shift 2 ;;
    --manifest) manifest="${2:?Missing manifest path}"; shift 2 ;;
    --keep) keep="${2:?Missing retention}"; shift 2 ;;
    *) fail 'Unknown backup option.' ;;
  esac
done
load_config
mkdir -p -- "$backup_dir" "$(dirname -- "$manifest")"
backup_dir="$(cd -- "$backup_dir" && pwd -P)"
[[ "$backup_dir" != / ]] || fail 'Backup directory resolves to root.'
chmod 0700 "$backup_dir"
# Serialize publication and retention across manual and timer invocations.
exec 9> "$backup_dir/.xcrawler-backup.lock"
flock -n 9 || fail 'Another backup is running.'
work="$(mktemp -d "$backup_dir/.xcrawler-backup.XXXXXXXX")"
manifest_tmp=''
published=0
bundle_created=0
bundle=''
cleanup() {
  local result=$?
  if (( ! published )); then
    if [[ -d "$work/prune" ]]; then
      while IFS= read -r -d '' old; do mv -T -- "$old" "$backup_dir/$(basename -- "$old")"; done < <(find "$work/prune" -mindepth 1 -maxdepth 1 -type d -print0)
    fi
    if (( bundle_created )) && [[ -d "$backup_dir/$bundle" ]]; then rm -rf -- "$backup_dir/$bundle"; fi
  fi
  rm -rf -- "$work"
  [[ -z "$manifest_tmp" ]] || rm -f -- "$manifest_tmp"
  if (( result )); then printf 'BACKUP FAILED: previous success manifest retained; run javmeta:backup-status.\n' >&2; fi
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
# Suppress tool diagnostics: connection errors can include credential-bearing strings.
pg_dump --no-password -Fc --file="$work/postgres.dump" 2>/dev/null
[[ -s "$work/postgres.dump" ]] || fail 'PostgreSQL dump is empty.'
pg_restore -l "$work/postgres.dump" > "$work/toc" 2>/dev/null
[[ -s "$work/toc" ]] || fail 'PostgreSQL dump has no readable table of contents.'
mongo_status='skipped (mongodump missing)'
if command -v mongodump >/dev/null 2>&1; then
  [[ -n "${CFG_MONGO_URI:-}" && -n "${CFG_MONGO_DATABASE:-}" ]] || fail 'MongoDB configuration missing.'
  # mongodump supports a YAML config file, so the URI never appears in argv.
  export BACKUP_MONGO_URI="$CFG_MONGO_URI"
  "$php_bin" "$backup_scripts/support.php" mongo > "$work/mongo.yml"
  mongodump --config="$work/mongo.yml" --db="$CFG_MONGO_DATABASE" --archive="$work/mongodb.archive.gz" --gzip > /dev/null 2>&1
  [[ -s "$work/mongodb.archive.gz" ]] || fail 'MongoDB dump is empty.'
  rm -f -- "$work/mongo.yml"
  unset BACKUP_MONGO_URI
  mongo_status=completed
else
  printf 'WARNING: mongodb: skipped (mongodump missing); MongoDB Settings/secrets are NOT protected.\n' >&2
fi
# One completed bundle per UTC day; reruns replace today's bundle, retaining daily coverage.
day="$(date -u +%Y%m%d)"
bundle="xcrawler-backup-${day}-$(basename -- "$work" | cut -d . -f 3)"
[[ ! -e "$backup_dir/$bundle" && ! -L "$backup_dir/$bundle" ]] || fail 'The generated backup folder already exists.'
export BACKUP_CREATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)" BACKUP_MONGO_STATUS="$mongo_status"
export BACKUP_DUMP_PATH="$backup_dir/$bundle/postgres.dump"
"$php_bin" "$backup_scripts/support.php" manifest > "$work/manifest.json"
mv -T -- "$work" "$backup_dir/$bundle"
bundle_created=1
work="$(mktemp -d "$backup_dir/.xcrawler-backup.XXXXXXXX")"
# Prepare publication on the manifest filesystem so rename remains atomic.
manifest_tmp="$(mktemp "$(dirname -- "$manifest")/.latest.XXXXXXXX")"
cp -- "$backup_dir/$bundle/manifest.json" "$manifest_tmp"
# Prune only names created by this script, never follow links or cross directory boundaries.
mapfile -d '' bundles < <(find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -name 'xcrawler-backup-*' -printf '%f\0' | sort -zr)
mkdir "$work/prune"
days=0
last_day=''
for entry in "${bundles[@]}"; do
  [[ "$entry" =~ ^xcrawler-backup-([0-9]{8})-[a-zA-Z0-9]{8}$ ]] || continue
  "$php_bin" "$backup_scripts/support.php" owns "$backup_dir/$entry" || continue
  entry_day="${BASH_REMATCH[1]}"
  if [[ "$entry_day" != "$last_day" ]]; then days=$((days + 1)); last_day="$entry_day"; fi
  if [[ "$entry" != "$bundle" && ( "$entry_day" == "$day" || "$days" -gt "$keep" ) ]]; then
    mv -T -- "$backup_dir/$entry" "$work/prune/$entry"
  fi
done
mv -f -- "$manifest_tmp" "$manifest"
published=1
manifest_tmp=''
printf 'Backup completed; PostgreSQL verified; mongodb: %s.\n' "$mongo_status"

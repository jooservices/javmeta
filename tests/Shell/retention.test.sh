#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
# shellcheck source=deploy/backup/retention.sh
source "$root/deploy/backup/retention.sh"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
backup="$tmp/backup root"
sentinel="$tmp/sentinel"
mkdir -p "$backup/daily" "$backup/dumps" "$sentinel"
printf 'keep me' > "$sentinel/value"
for name in '20260101 old' '20260102 "quoted"' '20260103 --option' '20260104 newest'; do
  mkdir -p "$backup/daily/$name"
  printf 'dump' > "$backup/dumps/$name.dump"
done
symlink="$backup/daily/20260100-link"
ln -s "$sentinel" "$symlink"
for bad in '' 0 -1 abc '1+1' 99999999999999999999; do
  if prune_backups "$backup/daily" "$bad" directories 2>/dev/null; then echo 'FAIL: invalid retention accepted'; exit 1; fi
done
[[ -d "$backup/daily/20260101 old" ]]
if prune_backups '' 2 directories; then echo 'FAIL: empty root accepted'; exit 1; fi
prune_backups "$backup/daily" 2 directories
prune_backups "$backup/dumps" 2 dumps
[[ ! -e "$backup/daily/20260101 old" && ! -e "$backup/daily/20260102 \"quoted\"" ]]
[[ -d "$backup/daily/20260103 --option" && -d "$backup/daily/20260104 newest" ]]
[[ ! -e "$backup/dumps/20260101 old.dump" && -f "$backup/dumps/20260104 newest.dump" ]]
[[ -L "$symlink" && "$(cat "$sentinel/value")" == 'keep me' ]]
# Exercise the daily script itself with a dump stub; no real service is contacted.
mkdir -p "$tmp/ws/scripts/docker" "$tmp/app"
cat > "$tmp/ws/scripts/docker/env-run" <<'STUB'
#!/usr/bin/env bash
printf 'fake dump'
STUB
chmod +x "$tmp/ws/scripts/docker/env-run"
JAVMETA_BACKUP_ROOT="$backup" JAVMETA_BACKUP_KEEP=1 JAVMETA_APP_DIR="$tmp/app" WORKSPACES_ROOT="$tmp/ws" bash "$root/deploy/backup/staging-backup.sh" > "$tmp/run"
[[ "$(find "$backup/daily" -mindepth 1 -maxdepth 1 -type d | wc -l)" -eq 1 ]]
[[ "$(cat "$sentinel/value")" == 'keep me' ]]
echo 'PASS: directory/dump retention preserves filename boundaries, validates roots/counts and keeps sibling sentinel.'

#!/usr/bin/env bash
# Real local Git repository and symlinks, stubs for every runtime/service command.
set -euo pipefail
source_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
mkdir -p "$source_root/.tmp"
test_dir="$(mktemp -d "$source_root/.tmp/production-test.XXXXXX")"
trap 'rm -rf -- "$test_dir"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
mkdir -p "$test_dir/repo/deploy" "$test_dir/bin" "$test_dir/node/shared/storage" "$test_dir/node/releases/old" "$test_dir/node/shared/embedder-venv/bin"
cp "$source_root/deploy/production.sh" "$test_dir/repo/deploy/production.sh"
export XCRAWLER_ROOT="$test_dir/node" CALLS="$test_dir/calls"
export XCRAWLER_BACKUP_FILE="$test_dir/backup.dump" XCRAWLER_BACKUP_RESTORE_VERIFIED=1
printf 'test backup' > "$XCRAWLER_BACKUP_FILE"
touch "$XCRAWLER_ROOT/shared/.env" "$XCRAWLER_ROOT/shared/embedder-venv/bin/uvicorn"
printf 'APP_ENV=production\n' > "$XCRAWLER_ROOT/shared/.env"
chmod +x "$XCRAWLER_ROOT/shared/embedder-venv/bin/uvicorn"
ln -s "$XCRAWLER_ROOT/releases/old" "$XCRAWLER_ROOT/current"
cat > "$test_dir/bin/stub" <<'STUB'
#!/usr/bin/env bash
printf '%s %s\n' "${0##*/}" "$*" >> "$CALLS"
if [[ "${0##*/}" == gh ]]; then
  [[ "${FAIL_STEP:-}" != offline ]] || exit 1
  if [[ "$*" == *check-runs* ]]; then
    [[ "${FAIL_STEP:-}" != empty ]] || exit 0
    if [[ "${FAIL_STEP:-}" == pending ]]; then printf 'in_progress\t\n'; exit 0; fi
    if [[ "${FAIL_STEP:-}" == checks ]]; then printf 'completed\tfailure\n'; else printf 'completed\tsuccess\n'; fi
  elif [[ "${FAIL_STEP:-}" == ci ]]; then printf '1\tfailure\n'; else printf '0\tpending\n'; fi
fi
if [[ "${0##*/}" == pg_restore ]]; then printf ';     dbname: xcrawler\n'; fi
if [[ "${0##*/}" == php && "$*" == *staging:readiness* ]]; then
  if [[ "${FAIL_STEP:-}" == readiness && "$*" != *--no-record* ]]; then printf '[FAIL] backup: proof failure\n'; exit 1; fi
  printf '[PASS] horizon: running\n[PASS] scheduler: fresh\nStaging is READY\n'
fi
if [[ "${0##*/}" == node && "${FAIL_STEP:-}" == ui ]]; then exit 1; fi
if [[ "${0##*/}" == php && "$*" == *migrate* && "${FAIL_STEP:-}" == migrate ]]; then exit 1; fi
STUB
chmod +x "$test_dir/bin/stub"
for command in composer php node sudo pg_restore gh; do ln -s stub "$test_dir/bin/$command"; done
export PATH="$test_dir/bin:$PATH"
git init -q --bare "$test_dir/origin.git"
git init -q -b develop "$test_dir/repo"
git -C "$test_dir/repo" config user.name test
git -C "$test_dir/repo" config user.email test@example.test
mkdir -p "$test_dir/repo/ui-tests" "$test_dir/repo/storage"
touch "$test_dir/repo/artisan" "$test_dir/repo/ui-tests/render-smoke.mjs" "$test_dir/repo/storage/.gitkeep"
git -C "$test_dir/repo" add .
git -C "$test_dir/repo" -c core.hooksPath=/dev/null commit -qm 'test: Fixture'
ancestor="$(git -C "$test_dir/repo" rev-parse HEAD)"
printf newer > "$test_dir/repo/newer"
git -C "$test_dir/repo" add newer
git -C "$test_dir/repo" -c core.hooksPath=/dev/null commit -qm 'test: Newer develop'
git -C "$test_dir/repo" remote add origin "$test_dir/origin.git"
git -C "$test_dir/repo" push -q origin develop
commit="$(git -C "$test_dir/repo" rev-parse HEAD)"
script="$test_dir/repo/deploy/production.sh"
if bash "$script" --ref develop > "$test_dir/log" 2>&1; then fail 'accepted symbolic ref'; fi
git -C "$test_dir/repo" checkout -qb candidate
printf outside > "$test_dir/repo/outside"
git -C "$test_dir/repo" add outside
git -C "$test_dir/repo" -c core.hooksPath=/dev/null commit -qm 'test: Outside develop'
outside="$(git -C "$test_dir/repo" rev-parse HEAD)"
git -C "$test_dir/repo" checkout -q develop
if bash "$script" --ref "$outside" > "$test_dir/log" 2>&1; then fail 'accepted commit outside develop'; fi
printf dirty > "$test_dir/repo/dirty"
if bash "$script" > "$test_dir/log" 2>&1; then fail 'accepted dirty tree'; fi
rm "$test_dir/repo/dirty"
for failure in ci checks pending offline empty; do
  if FAIL_STEP="$failure" bash "$script" > "$test_dir/log" 2>&1; then fail "accepted $failure CI"; fi
done
# Hide gh discovery without changing the availability of the other system tools.
command() {
  if [[ "$*" == '-v gh' ]]; then return 1; fi
  builtin command "$@"
}
export -f command
if bash "$script" > "$test_dir/log" 2>&1; then fail 'accepted missing gh'; fi
grep -q 'gh is missing' "$test_dir/log" || fail 'missing gh warning'
bash "$script" --skip-ci-check > "$test_dir/log" 2>&1
grep -q BYPASSED "$test_dir/log" || fail 'missing gh bypass warning'
unset -f command
FAIL_STEP=offline bash "$script" --skip-ci-check > "$test_dir/log" 2>&1
grep -q BYPASSED "$test_dir/log" || fail 'missing CI bypass warning'
bash "$script" --ref "$ancestor" > "$test_dir/log" 2>&1
grep -q "archive $ancestor" "$test_dir/log" || fail 'ancestor SHA not selected'
rm "$CALLS"
bash "$script" > "$test_dir/log" 2>&1
grep -q "archive $commit" "$test_dir/log" || fail 'default is not origin/develop'
grep -q 'DRY RUN:.*migrate' "$test_dir/log" || fail 'missing dry run plan'
[[ ! -e "$XCRAWLER_ROOT/releases/$(date -u +%Y%m%dT%H%M%SZ)-${commit:0:7}" ]] || fail 'dry run mutated node'
if XCRAWLER_BACKUP_FILE="$test_dir/missing" bash "$script" --apply > "$test_dir/log" 2>&1; then fail 'accepted missing backup'; fi
FAIL_STEP=ui bash "$script" --apply > "$test_dir/log" 2>&1 && fail 'ignored UI failure'
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'UI failure changed current'
! grep -q 'artisan migrate' "$CALLS" || fail 'migrated before UI gate'
rm -rf "$XCRAWLER_ROOT"/releases/*-"${commit:0:7}"
if FAIL_STEP=migrate bash "$script" --apply > "$test_dir/log" 2>&1; then fail 'ignored migration failure'; fi
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'migration failure changed current'
rm -rf "$XCRAWLER_ROOT"/releases/*-"${commit:0:7}"
# A failing restart exercises ERR rollback immediately after the symlink switch.
rm "$test_dir/bin/sudo"
cat > "$test_dir/bin/sudo" <<'STUB'
#!/usr/bin/env bash
printf 'sudo %s\n' "$*" >> "$CALLS"
[[ "$*" != *'restart php8.5-fpm xcrawler-embedder xcrawler-horizon' ]]
STUB
chmod +x "$test_dir/bin/sudo"
if bash "$script" --apply > "$test_dir/log" 2>&1; then fail 'ignored restart failure'; fi
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'did not roll symlink back'
grep -q 'Database migrations are NOT reversed' "$test_dir/log" || fail 'missing DB rollback warning'
rm "$test_dir/bin/sudo"
ln -s stub "$test_dir/bin/sudo"
rm -rf "$XCRAWLER_ROOT"/releases/*-"${commit:0:7}"
if FAIL_STEP=readiness bash "$script" --apply > "$test_dir/log" 2>&1; then fail 'ignored final readiness failure'; fi
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'readiness failure did not restore old release'
rm -rf "$XCRAWLER_ROOT"/releases/*-"${commit:0:7}"
bash "$script" --apply > "$test_dir/log" 2>&1
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT"/releases/*-"${commit:0:7}" ]] || fail 'release not activated'
grep -q 'staging:readiness' "$CALLS" || fail 'readiness not checked'
echo 'OK (19 production deployment scenarios)'

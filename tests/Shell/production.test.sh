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
if [[ "${0##*/}" == gh && "${FAIL_STEP:-}" == draft ]]; then printf '{"isDraft":true,"isPrerelease":false,"tagName":"v1.2.3"}\n'; exit 0; fi
if [[ "${0##*/}" == gh ]]; then printf '{"isDraft":false,"isPrerelease":false,"tagName":"v1.2.3"}\n'; fi
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
git init -q -b master "$test_dir/repo"
git -C "$test_dir/repo" config user.name test
git -C "$test_dir/repo" config user.email test@example.test
mkdir -p "$test_dir/repo/ui-tests" "$test_dir/repo/storage"
touch "$test_dir/repo/artisan" "$test_dir/repo/ui-tests/render-smoke.mjs" "$test_dir/repo/storage/.gitkeep"
git -C "$test_dir/repo" add .
git -C "$test_dir/repo" -c core.hooksPath=/dev/null commit -qm 'test: Fixture'
git -C "$test_dir/repo" tag -am release v1.2.3
git -C "$test_dir/repo" tag v1.2.4
git -C "$test_dir/repo" remote add origin "$test_dir/origin.git"
git -C "$test_dir/repo" push -q --tags origin master
script="$test_dir/repo/deploy/production.sh"
if bash "$script" master > "$test_dir/log" 2>&1; then fail 'accepted branch'; fi
if bash "$script" v1.2.4 > "$test_dir/log" 2>&1; then fail 'accepted lightweight tag'; fi
git -C "$test_dir/repo" checkout -qb candidate
printf not-master > "$test_dir/repo/not-master"
git -C "$test_dir/repo" add not-master
git -C "$test_dir/repo" -c core.hooksPath=/dev/null commit -qm 'test: Unreleased'
git -C "$test_dir/repo" tag -am unreleased v9.9.9
git -C "$test_dir/repo" push -q origin v9.9.9
git -C "$test_dir/repo" checkout -q master
if bash "$script" v9.9.9 > "$test_dir/log" 2>&1; then fail 'accepted a tag outside master'; fi
printf dirty > "$test_dir/repo/dirty"
if bash "$script" v1.2.3 > "$test_dir/log" 2>&1; then fail 'accepted dirty tree'; fi
rm "$test_dir/repo/dirty"
if FAIL_STEP=draft bash "$script" v1.2.3 > "$test_dir/log" 2>&1; then fail 'accepted draft release'; fi
rm "$CALLS"
bash "$script" v1.2.3 > "$test_dir/log" 2>&1
grep -q 'DRY RUN:.*migrate' "$test_dir/log" || fail 'missing dry run plan'
[[ "$(cat "$CALLS")" == 'gh release view v1.2.3 --repo jooservices/javmeta --json isDraft,isPrerelease,tagName' && ! -e "$XCRAWLER_ROOT/releases/v1.2.3" ]] || fail 'dry run mutated node'
if XCRAWLER_BACKUP_FILE="$test_dir/missing" bash "$script" v1.2.3 --apply > "$test_dir/log" 2>&1; then fail 'accepted missing backup'; fi
FAIL_STEP=ui bash "$script" v1.2.3 --apply > "$test_dir/log" 2>&1 && fail 'ignored UI failure'
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'UI failure changed current'
! grep -q 'artisan migrate' "$CALLS" || fail 'migrated before UI gate'
rm -rf "$XCRAWLER_ROOT/releases/v1.2.3"
# A failing restart exercises ERR rollback immediately after the symlink switch.
rm "$test_dir/bin/sudo"
cat > "$test_dir/bin/sudo" <<'STUB'
#!/usr/bin/env bash
printf 'sudo %s\n' "$*" >> "$CALLS"
[[ "$*" != *'restart php8.5-fpm xcrawler-embedder xcrawler-horizon' ]]
STUB
chmod +x "$test_dir/bin/sudo"
if bash "$script" v1.2.3 --apply > "$test_dir/log" 2>&1; then fail 'ignored restart failure'; fi
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'did not roll symlink back'
grep -q 'Database migrations are NOT reversed' "$test_dir/log" || fail 'missing DB rollback warning'
rm "$test_dir/bin/sudo"
ln -s stub "$test_dir/bin/sudo"
rm -rf "$XCRAWLER_ROOT/releases/v1.2.3"
if FAIL_STEP=readiness bash "$script" v1.2.3 --apply > "$test_dir/log" 2>&1; then fail 'ignored final readiness failure'; fi
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/old" ]] || fail 'readiness failure did not restore old release'
rm -rf "$XCRAWLER_ROOT/releases/v1.2.3"
bash "$script" v1.2.3 --apply > "$test_dir/log" 2>&1
[[ "$(readlink "$XCRAWLER_ROOT/current")" == "$XCRAWLER_ROOT/releases/v1.2.3" ]] || fail 'release not activated'
grep -q 'staging:readiness' "$CALLS" || fail 'readiness not checked'
echo 'OK (11 production deployment scenarios)'

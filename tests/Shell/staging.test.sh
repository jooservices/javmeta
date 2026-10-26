#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
STAGING_SCRIPT="${SCRIPT_DIR}/../../deploy/staging.sh"
TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-staging-test.XXXXXX")"
trap 'rm -rf -- "${TEST_TMP}"' EXIT INT TERM

fail_test() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

WS="${TEST_TMP}/ws"
APP="${WS}/JOOservices/projects/javmeta"
ORIGIN="${TEST_TMP}/origin.git"
CALLS="${TEST_TMP}/calls.log"
export HOME="${TEST_TMP}/home"
mkdir -p "${HOME}" "${WS}/scripts/docker" "${TEST_TMP}/bin"
export JAVMETA_DEPLOY_STATE_DIR="${TEST_TMP}/state with spaces"
export PATH="${TEST_TMP}/bin:${PATH}"
export CALLS

# Stubs: every command is recorded; the migration can be made to fail with FAIL_STEP=migrate.
cat > "${WS}/scripts/docker/runtime" <<'STUB'
#!/usr/bin/env bash
printf 'runtime %s\n' "$*" >> "${CALLS}"
if [[ "${FAIL_STEP:-}" == migrate && "$*" == *migrate* ]]; then echo 'migration failed' >&2; exit 1; fi
if [[ "${FAIL_STEP:-}" == import && "$*" == *settings:import-env* ]]; then echo 'settings import failed' >&2; exit 1; fi
if [[ "$*" == *render-smoke.mjs* ]]; then
  [[ -f file.txt ]] || exit 2
  printf 'ui-candidate %s\n' "$PWD" >> "${CALLS}"
  if [[ "${FAIL_STEP:-}" == ui ]]; then echo 'broken UI' >&2; exit 1; fi
fi
if [[ "$*" == *staging:readiness* ]]; then
  if [[ "${DELAY_HEARTBEAT:-0}" == 1 && ! -f "${CALLS}.heartbeat" ]]; then
    touch "${CALLS}.heartbeat"
    echo '[FAIL] horizon: starting'
    echo '[FAIL] scheduler: no heartbeat yet'
    exit 1
  fi
  echo '[PASS] horizon: running'
  echo '[PASS] scheduler: fresh heartbeat'
  if [[ "${FAIL_STEP:-}" == missing-ready ]]; then echo 'unexpected report'; exit 0; fi
  if [[ "${FAIL_STEP:-}" == readiness ]]; then
    echo '[FAIL] backup: stale backup'
    echo 'Staging is NOT READY'
    exit 1
  fi
  echo 'Staging is READY'
fi
STUB
cat > "${WS}/scripts/docker/shared" <<'STUB'
#!/usr/bin/env bash
printf 'shared %s\n' "$*" >> "${CALLS}"
STUB
cat > "${WS}/scripts/docker/env-run" <<'STUB'
#!/usr/bin/env bash
printf 'env-run %s\n' "$*" >> "${CALLS}"
if [[ "$*" == *pg_dump* ]]; then printf 'FAKE-DUMP'; else cat >/dev/null; fi
STUB
cat > "${TEST_TMP}/bin/systemctl" <<'STUB'
#!/usr/bin/env bash
printf 'systemctl %s\n' "$*" >> "${CALLS}"
STUB
cat > "${TEST_TMP}/bin/sleep" <<'STUB'
#!/usr/bin/env bash
printf 'sleep %s\n' "$*" >> "${CALLS}"
STUB
chmod +x "${WS}/scripts/docker/"* "${TEST_TMP}/bin/systemctl" "${TEST_TMP}/bin/sleep"

git init -q --bare "${ORIGIN}"
git init -q -b develop "${APP}"
git -C "${APP}" config user.email test@example.test
git -C "${APP}" config user.name test
git -C "${APP}" remote add origin "${ORIGIN}"
printf 'one\n' > "${APP}/file.txt"
printf 'APP_ENV=staging\n' > "${APP}/.env"
printf '.env\n' > "${APP}/.gitignore"
git -C "${APP}" add file.txt .gitignore
git -C "${APP}" commit -q -m 'chore: First'
git -C "${APP}" push -q origin develop
first="$(git -C "${APP}" rev-parse HEAD)"

push_new_commit() {
  local other="${TEST_TMP}/other"
  rm -rf "${other}"
  git clone -q -b develop "${ORIGIN}" "${other}"
  git -C "${other}" config user.email test@example.test
  git -C "${other}" config user.name test
  printf 'two\n' >> "${other}/file.txt"
  git -C "${other}" commit -q -am 'chore: Second'
  git -C "${other}" push -q origin develop
  git -C "${other}" rev-parse HEAD
}

run_staging() {
  : > "${CALLS}"
  local output status=0
  # Capture the pipe until tee closes so assertions cannot race the deploy log writer.
  output="$(JAVMETA_APP_DIR="${APP}" WORKSPACES_ROOT="${WS}" bash "${STAGING_SCRIPT}" "$@" 2>&1)" || status=$?
  printf '%s\n' "${output}"
  return "${status}"
}

# 1. Dry run: prints the steps and changes nothing.
second="$(push_new_commit)"
out="$(run_staging --dry-run 2>&1)" || fail_test "dry run failed: ${out}"
[[ "${out}" == *'DRY RUN'* ]] || fail_test 'dry run printed no steps.'
for step in 'node node ui-tests/render-smoke.mjs' 'settings:import-env' '90 seconds' 'staging:readiness'; do
  [[ "${out}" == *"${step}"* ]] || fail_test "dry run missing gate: ${step}"
done
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${first}" ]] || fail_test 'dry run moved the code.'
[[ "${out}" == *'javmeta-media-worker.service'* ]] || fail_test 'dry run omitted the media worker.'
! grep -q 'migrate' "${CALLS}" || fail_test 'dry run ran the migration.'
! grep -q '^runtime ' "${CALLS}" || fail_test 'dry run executed a runtime gate.'

# 2. Wrong branch is refused.
git -C "${APP}" checkout -q -b feature
if run_staging >"${TEST_TMP}/wrong-branch.log" 2>&1; then fail_test 'a deployment ran on the wrong branch.'; fi
grep -q 'must be on branch develop' "${TEST_TMP}/wrong-branch.log" || fail_test 'wrong branch message missing.'
git -C "${APP}" checkout -q develop

# 3. A dirty tree is refused.
printf 'dirty\n' >> "${APP}/file.txt"
if run_staging >"${TEST_TMP}/dirty.log" 2>&1; then fail_test 'a deployment ran on a dirty tree.'; fi
grep -q 'uncommitted changes' "${TEST_TMP}/dirty.log" || fail_test 'dirty tree message missing.'
git -C "${APP}" checkout -q -- file.txt

# 4. A normal deployment pulls, migrates forward, sets up search, restarts and checks.
# Quick-backup pruning keeps each filename intact and cannot touch a sibling.
export JAVMETA_DEPLOY_BACKUP_KEEP=2
mkdir -p "${JAVMETA_DEPLOY_STATE_DIR}/backups" "${TEST_TMP}/state"
printf 'sentinel' > "${TEST_TMP}/state/sentinel"
printf 'old' > "${JAVMETA_DEPLOY_STATE_DIR}/backups/javmeta-20000101 old.dump"
printf 'newer' > "${JAVMETA_DEPLOY_STATE_DIR}/backups/javmeta-20000102 \"quoted\".dump"
run_staging >"${TEST_TMP}/ok.log" 2>&1 || fail_test "deployment failed: $(tail -5 "${TEST_TMP}/ok.log")"
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${second}" ]] || fail_test 'the deployment did not pull the new commit.'
[[ ! -e "${JAVMETA_DEPLOY_STATE_DIR}/backups/javmeta-20000101 old.dump" ]] || fail_test 'old quick backup survived.'
[[ -f "${JAVMETA_DEPLOY_STATE_DIR}/backups/javmeta-20000102 \"quoted\".dump" ]] || fail_test 'newer quick backup was removed.'
[[ "$(cat "${TEST_TMP}/state/sentinel")" == sentinel ]] || fail_test 'sibling sentinel was touched.'
for step in 'pg_dump' 'composer install --no-dev' 'artisan migrate --force' 'artisan settings:import-env' 'artisan es:setup' 'artisan optimize' 'artisan javmeta:smoke' 'artisan staging:readiness'; do
  grep -q -- "${step}" "${CALLS}" || fail_test "step missing: ${step}"
done
ordered="$(grep -n -E 'artisan migrate --force|stop javmeta-scheduler|restart javmeta-horizon|restart javmeta-media-worker|start javmeta-scheduler|javmeta:smoke' "${CALLS}" | sed 's/^[0-9]*://')"
expected=$'systemctl --user stop javmeta-scheduler.service\nruntime php@8.5 php artisan migrate --force\nsystemctl --user stop javmeta-scheduler.service\nsystemctl --user restart javmeta-horizon.service\nsystemctl --user restart javmeta-media-worker.service\nsystemctl --user start javmeta-scheduler.service\nruntime php@8.5 php artisan javmeta:smoke'
[[ "${ordered}" == "${expected}" ]] || fail_test "wrong step order: ${ordered}"
ls "${JAVMETA_DEPLOY_STATE_DIR}"/backups/*.dump >/dev/null 2>&1 || fail_test 'no quick backup file.'
ui_line="$(grep -n 'node node ui-tests/render-smoke.mjs' "${CALLS}" | cut -d: -f1)"
stop_line="$(grep -n 'systemctl --user stop' "${CALLS}" | head -1 | cut -d: -f1)"
(( ui_line < stop_line )) || fail_test 'services stopped before UI smoke.'
grep -q 'ui-candidate .*ui-candidate\.' "${CALLS}" || fail_test 'UI smoke did not use an isolated candidate.'
import_line="$(grep -n 'settings:import-env' "${CALLS}" | cut -d: -f1)"
migrate_line="$(grep -n 'artisan migrate --force' "${CALLS}" | cut -d: -f1)"
(( import_line == migrate_line + 1 )) || fail_test 'settings import did not immediately follow migration.'

# 5. A failed migration returns the code to the previous commit and restores the backup.
before="$(git -C "${APP}" rev-parse HEAD)"
third="$(push_new_commit)"
[[ "${third}" != "${before}" ]] || fail_test 'test setup: no new commit.'
if FAIL_STEP=migrate run_staging >"${TEST_TMP}/failed.log" 2>&1; then fail_test 'a failed migration ended as success.'; fi
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${before}" ]] || fail_test 'the code was not returned to the previous commit.'
grep -q 'pg_restore' "${CALLS}" || fail_test 'the backup was not restored after the failed migration.'
grep -q 'restoring the quick backup' "${TEST_TMP}/failed.log" || fail_test 'rollback message missing.'

# 6. A broken candidate never changes the running release or stops/restarts services.
if FAIL_STEP=ui run_staging >"${TEST_TMP}/ui-failed.log" 2>&1; then fail_test 'broken UI passed the gate.'; fi
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${before}" ]] || fail_test 'UI failure moved the running release.'
! grep -q 'systemctl .*\(stop\|start\|restart\)' "${CALLS}" || fail_test 'UI failure changed services.'
! grep -q 'artisan migrate' "${CALLS}" || fail_test 'UI failure ran migrations.'
grep -q 'UI render smoke failed' "${TEST_TMP}/ui-failed.log" || fail_test 'UI gate failure message missing.'
! find "${JAVMETA_DEPLOY_STATE_DIR}" -name 'ui-candidate.*' | grep -q . || fail_test 'candidate directory leaked.'

# 7. A non-READY report fails deployment, prints the failing lines and rolls code back.
if FAIL_STEP=readiness run_staging >"${TEST_TMP}/readiness-failed.log" 2>&1; then fail_test 'NOT READY passed the gate.'; fi
grep -q '\[FAIL\] backup: stale backup' "${TEST_TMP}/readiness-failed.log" || fail_test 'failing readiness lines missing.'
grep -q 'existing rollback' "${TEST_TMP}/readiness-failed.log" || fail_test 'rollback hint missing.'
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${before}" ]] || fail_test 'readiness failure did not roll code back.'
! grep -q pg_restore "${CALLS}" || fail_test 'readiness failure restored the database after successful migration.'

# 8. Only explicit skips bypass gates and both produce loud warnings.
JAVMETA_SKIP_UI_SMOKE=1 JAVMETA_SKIP_READINESS=1 run_staging >"${TEST_TMP}/skip.log" 2>&1 || fail_test 'explicit skips failed.'
for gate in UI_SMOKE READINESS; do
  grep -q "WARNING: JAVMETA_SKIP_${gate}=1.*BYPASSED" "${TEST_TMP}/skip.log" || fail_test "skip warning missing: ${gate}"
done
! grep -q 'render-smoke\|staging:readiness' "${CALLS}" || fail_test 'skipped gate executed.'
grep -q settings:import-env "${CALLS}" || fail_test 'skips bypassed settings import.'

# 9. Exit zero alone does not prove that readiness returned READY.
if FAIL_STEP=missing-ready run_staging >"${TEST_TMP}/missing-ready.log" 2>&1; then fail_test 'missing READY was accepted.'; fi
grep -q 'NOT READY' "${TEST_TMP}/missing-ready.log" || fail_test 'missing READY failure was not explained.'

# 10. An import failure must stop before search setup or service restart.
if FAIL_STEP=import run_staging >"${TEST_TMP}/import-failed.log" 2>&1; then fail_test 'settings import failure was ignored.'; fi
! grep -q 'artisan es:setup\|systemctl --user restart' "${CALLS}" || fail_test 'deployment continued after import failure.'
! grep -q pg_restore "${CALLS}" || fail_test 'import failure restored a completed migration.'

# 11. Values other than exactly 1 cannot silently disable either gate.
JAVMETA_SKIP_UI_SMOKE=true JAVMETA_SKIP_READINESS=true run_staging >"${TEST_TMP}/no-skip.log" 2>&1 || fail_test 'normal gates failed.'
for step in render-smoke.mjs staging:readiness; do
  grep -q "${step}" "${CALLS}" || fail_test "non-1 value skipped ${step}"
done
! grep -q BYPASSED "${TEST_TMP}/no-skip.log" || fail_test 'non-1 value logged a bypass.'

# 12. Freshly restarted services get time to publish their heartbeat before the final report.
DELAY_HEARTBEAT=1 run_staging >"${TEST_TMP}/wait.log" 2>&1 || fail_test 'heartbeat retry failed.'
grep -q '^sleep 5$' "${CALLS}" || fail_test 'missing heartbeat was not given a retry interval.'
[[ "$(grep -c 'staging:readiness --no-record' "${CALLS}")" == 2 ]] || fail_test 'heartbeat readiness was not retried.'
grep -q 'Staging is READY' "${TEST_TMP}/wait.log" || fail_test 'final READY report missing.'

echo 'PASS: 12 staging deployment scenarios: order, guards, dry run, rollback and gates.'

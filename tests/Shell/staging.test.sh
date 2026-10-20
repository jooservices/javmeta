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
export JAVMETA_DEPLOY_STATE_DIR="${TEST_TMP}/state"
export PATH="${TEST_TMP}/bin:${PATH}"
export CALLS

# Stubs: every command is recorded; the migration can be made to fail with FAIL_STEP=migrate.
cat > "${WS}/scripts/docker/runtime" <<'STUB'
#!/usr/bin/env bash
printf 'runtime %s\n' "$*" >> "${CALLS}"
if [[ "${FAIL_STEP:-}" == migrate && "$*" == *migrate* ]]; then echo 'migration failed' >&2; exit 1; fi
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
chmod +x "${WS}/scripts/docker/"* "${TEST_TMP}/bin/systemctl"

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

run_staging() { : > "${CALLS}"; JAVMETA_APP_DIR="${APP}" WORKSPACES_ROOT="${WS}" bash "${STAGING_SCRIPT}" "$@"; }

# 1. Dry run: prints the steps and changes nothing.
second="$(push_new_commit)"
out="$(run_staging --dry-run 2>&1)" || fail_test "dry run failed: ${out}"
[[ "${out}" == *'DRY RUN'* ]] || fail_test 'dry run printed no steps.'
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${first}" ]] || fail_test 'dry run moved the code.'
! grep -q 'migrate' "${CALLS}" || fail_test 'dry run ran the migration.'

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
run_staging >"${TEST_TMP}/ok.log" 2>&1 || fail_test "deployment failed: $(tail -5 "${TEST_TMP}/ok.log")"
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${second}" ]] || fail_test 'the deployment did not pull the new commit.'
for step in 'pg_dump' 'composer install --no-dev' 'artisan migrate --force' 'artisan es:setup' 'artisan optimize' 'artisan javmeta:smoke'; do
  grep -q -- "${step}" "${CALLS}" || fail_test "step missing: ${step}"
done
ordered="$(grep -n -E 'artisan migrate --force|stop javmeta-scheduler|restart javmeta-horizon|start javmeta-scheduler|javmeta:smoke' "${CALLS}" | sed 's/^[0-9]*://')"
expected=$'runtime php@8.5 php artisan migrate --force\nsystemctl --user stop javmeta-scheduler.service\nsystemctl --user restart javmeta-horizon.service\nsystemctl --user start javmeta-scheduler.service\nruntime php@8.5 php artisan javmeta:smoke'
[[ "${ordered}" == "${expected}" ]] || fail_test "wrong step order: ${ordered}"
ls "${JAVMETA_DEPLOY_STATE_DIR}"/backups/*.dump >/dev/null 2>&1 || fail_test 'no quick backup file.'

# 5. A failed migration returns the code to the previous commit and restores the backup.
before="$(git -C "${APP}" rev-parse HEAD)"
third="$(push_new_commit)"
[[ "${third}" != "${before}" ]] || fail_test 'test setup: no new commit.'
if FAIL_STEP=migrate run_staging >"${TEST_TMP}/failed.log" 2>&1; then fail_test 'a failed migration ended as success.'; fi
[[ "$(git -C "${APP}" rev-parse HEAD)" == "${before}" ]] || fail_test 'the code was not returned to the previous commit.'
grep -q 'pg_restore' "${CALLS}" || fail_test 'the backup was not restored after the failed migration.'
grep -q 'restoring the quick backup' "${TEST_TMP}/failed.log" || fail_test 'rollback message missing.'

echo 'PASS: staging deployment order, guards, dry run and rollback.'

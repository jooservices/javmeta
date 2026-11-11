#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
CHECKER="${ROOT}/tools/quality/check-git-conventions.sh"
TEMP_ROOT="${ROOT}/.tmp"
CREATED_TEMP_ROOT=0
if [[ ! -d "$TEMP_ROOT" ]]; then
  mkdir -p "$TEMP_ROOT"
  CREATED_TEMP_ROOT=1
fi
TEST_TMP="$(mktemp -d "${TEMP_ROOT}/git-conventions.XXXXXX")"

cleanup() {
  rm -rf -- "$TEST_TMP"
  if (( CREATED_TEMP_ROOT == 1 )); then
    rmdir -- "$TEMP_ROOT" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

expect_pass() {
  local label="$1"
  shift
  if ! "$@" >"${TEST_TMP}/output" 2>&1; then
    cat "${TEST_TMP}/output" >&2
    fail "$label should pass"
  fi
}

expect_fail_with() {
  local label="$1"
  local expected="$2"
  shift 2
  if "$@" >"${TEST_TMP}/output" 2>&1; then
    fail "$label should fail"
  fi
  if ! grep -Fq -- "$expected" "${TEST_TMP}/output"; then
    cat "${TEST_TMP}/output" >&2
    fail "$label should explain: $expected"
  fi
}

expect_pass "valid PR title" bash "$CHECKER" title 'feat(ui): Add shared git checks'
expect_pass "valid breaking-change title" bash "$CHECKER" title 'fix(core)!: Reject unsafe trailers'
expect_pass "PR title matching the existing workflow pattern" bash "$CHECKER" title 'docs: Add a title ending with a period.'
expect_fail_with "lowercase subject" 'uppercase ASCII subject' bash "$CHECKER" title 'feat(ui): add shared git checks'
expect_fail_with "one-character PR subject" 'at least two characters' bash "$CHECKER" title 'feat: A'
expect_fail_with "unknown type" 'allowed Conventional Commit type' bash "$CHECKER" title 'feature(ui): Add shared git checks'
expect_fail_with "multiline title" 'single line' bash "$CHECKER" title $'feat(ui): Add checks\nbody'

PR_WRAPPER="${ROOT}/tools/quality/create-pr.sh"
FAKE_BIN="${TEST_TMP}/bin"
GH_LOG="${TEST_TMP}/gh.log"
mkdir -p "$FAKE_BIN"
cat >"${FAKE_BIN}/gh" <<'GH'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"${GH_LOG}"
GH
chmod +x "${FAKE_BIN}/gh"
export GH_LOG
PATH="${FAKE_BIN}:${PATH}"
export PATH

: >"${GH_LOG}"
expect_pass "PR wrapper accepts --title" "$PR_WRAPPER" --title 'feat(ui): Add a checked pull request' --base develop
grep -Fq 'pr create --title feat(ui): Add a checked pull request --base develop' "${GH_LOG}" || fail "PR wrapper must pass options to gh"

: >"${GH_LOG}"
expect_pass "PR wrapper accepts --title=value" "$PR_WRAPPER" --title='fix: Reject invalid local ranges' --draft
grep -Fq 'pr create --title=fix: Reject invalid local ranges --draft' "${GH_LOG}" || fail "PR wrapper must preserve --title=value"

: >"${GH_LOG}"
expect_pass "PR wrapper accepts -t" "$PR_WRAPPER" -t 'docs: Document local submission checks'
grep -Fq 'pr create -t docs: Document local submission checks' "${GH_LOG}" || fail "PR wrapper must preserve -t"

: >"${GH_LOG}"
expect_fail_with "PR wrapper rejects an invalid title before gh" 'uppercase ASCII subject' "$PR_WRAPPER" --title 'fix: lowercase title'
[[ ! -s "${GH_LOG}" ]] || fail "PR wrapper must reject invalid titles before invoking gh"
expect_fail_with "PR wrapper requires an explicit title" 'requires an explicit title' "$PR_WRAPPER" --base develop
expect_fail_with "PR wrapper rejects an empty title before gh" 'allowed Conventional Commit type' "$PR_WRAPPER" --title ''
expect_fail_with "PR wrapper does not mistake another option's value for the title" 'requires an explicit title' "$PR_WRAPPER" --body 'feat: Valid but only a body' --base develop
expect_fail_with "PR wrapper skips attachment option values while finding the title" 'requires an explicit title' "$PR_WRAPPER" --attach --title 'feat: Valid but only an attachment path'
expect_fail_with "PR wrapper blocks interactive title changes after validation" 'allows the submitted title to change' "$PR_WRAPPER" --title 'feat: Add a checked title' --web
expect_fail_with "PR wrapper blocks alternate web mode values" 'allows the submitted title to change' "$PR_WRAPPER" --title 'feat: Add a checked title' --web=true
[[ ! -s "${GH_LOG}" ]] || fail "PR wrapper must not invoke gh without a valid title"

MESSAGE_FILE="${TEST_TMP}/message.txt"
cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Add a local checker

Ticket: T16
MESSAGE
expect_pass "valid commit message and ticket trailer" bash "$CHECKER" commit-message "$MESSAGE_FILE"

cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Add a local checker

Co-Authored-By: Codex <codex@example.invalid>
MESSAGE
expect_fail_with "coauthor trailer" 'prohibited AI/coauthor trailer' bash "$CHECKER" commit-message "$MESSAGE_FILE"

cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Add a local checker

Generated with Codex

# Please enter the commit message for your changes. Lines starting with '#' will be ignored.
MESSAGE
expect_fail_with "generated-with trailer before Git editor comments" 'Generated with' bash "$CHECKER" commit-message "$MESSAGE_FILE"

cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Add a local checker

Generated with Codex

Ticket: T16
MESSAGE
expect_fail_with "generated-with attribution before a later trailer paragraph" 'Generated with' bash "$CHECKER" commit-message "$MESSAGE_FILE"

REPO="${TEST_TMP}/repo"
mkdir -p "$REPO"
git -C "$REPO" init -q
git -C "$REPO" config user.name 'T16 Test'
git -C "$REPO" config user.email 't16-test@example.invalid'
git -C "$REPO" config commit.gpgsign false
git -C "$REPO" commit --allow-empty -q -m 'chore: Create local test base'
BASE="$(git -C "$REPO" rev-parse HEAD)"
git -C "$REPO" update-ref refs/remotes/origin/develop "$BASE"
git -C "$REPO" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/develop

cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Add a valid trailer

Ticket: T16
MESSAGE
git -C "$REPO" commit --allow-empty -q -F "$MESSAGE_FILE"
git -C "$REPO" commit --allow-empty -q -m 'fix: Keep a second commit local'
GOOD_HEAD="$(git -C "$REPO" rev-parse HEAD)"
(
  cd "$REPO"
  expect_pass "multiple local commits" bash "$CHECKER" commits "${BASE}..HEAD"
)

PUSH_INPUT="HEAD ${GOOD_HEAD} refs/heads/feature ${BASE}"
ZERO_OID="0000000000000000000000000000000000000000"
PUSH_REVISION_INPUT="HEAD~0 ${GOOD_HEAD} refs/heads/revision ${ZERO_OID}"
(
  cd "$REPO"
  expect_pass "pre-push checks the exact existing-branch range" bash "$CHECKER" pre-push origin "$PUSH_INPUT"
  expect_pass "pre-push accepts a local revision expression as the source ref" bash "$CHECKER" pre-push origin "$PUSH_REVISION_INPUT"
)

# develop merged into an existing remote branch: the pushed range then also lists develop's own commits, which
# were validated when they landed and must not be judged again (an old trailer there must not block the push).
(
  cd "$REPO"
  git checkout -q -b merged-feature "$BASE"
  git commit --allow-empty -q -m 'feat: Branch work before the merge'
  OLD_BRANCH_HEAD="$(git rev-parse HEAD)"
  git checkout -q "$BASE"
  printf 'feat: Landed on develop earlier\n\nCo-Authored-By: Someone <someone@example.invalid>\n' > "$MESSAGE_FILE"
  git commit --allow-empty -q -F "$MESSAGE_FILE"
  git update-ref refs/remotes/origin/develop "$(git rev-parse HEAD)"
  git checkout -q merged-feature
  git merge -q --no-ff -m 'chore: Merge develop into the feature' origin/develop
  MERGED_HEAD="$(git rev-parse HEAD)"
  expect_pass "pre-push ignores commits that are already on the remote default branch" \
    bash "$CHECKER" pre-push origin "refs/heads/merged-feature ${MERGED_HEAD} refs/heads/merged-feature ${OLD_BRANCH_HEAD}"
  git checkout -q -b own-bad "$MERGED_HEAD"
  printf 'feat: A bad commit of this branch\n\nCo-Authored-By: Someone <someone@example.invalid>\n' > "$MESSAGE_FILE"
  git commit --allow-empty -q -F "$MESSAGE_FILE"
  expect_fail_with "pre-push still rejects a bad commit that belongs to the branch" 'prohibited AI/coauthor trailer' \
    bash "$CHECKER" pre-push origin "refs/heads/own-bad $(git rev-parse HEAD) refs/heads/merged-feature ${MERGED_HEAD}"
)

NEW_BRANCH_INPUT="refs/heads/new-feature ${GOOD_HEAD} refs/heads/new-feature ${ZERO_OID}"
(
  cd "$REPO"
  expect_pass "pre-push checks a new branch against the local remote default" bash "$CHECKER" pre-push origin "$NEW_BRANCH_INPUT"
  expect_pass "pre-push accepts branch deletion updates" bash "$CHECKER" pre-push origin "refs/heads/feature ${ZERO_OID} refs/heads/feature ${GOOD_HEAD}"
)

cat >"${MESSAGE_FILE}" <<'MESSAGE'
docs: Record an invalid footer

Co-authored-by: Tool <tool@example.invalid>
MESSAGE
git -C "$REPO" commit --allow-empty -q -F "$MESSAGE_FILE"
(
  cd "$REPO"
  expect_fail_with "multi-commit range with forbidden trailer" 'prohibited AI/coauthor trailer' bash "$CHECKER" commits "${BASE}..HEAD"
)

cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Reproduce a Git editor message

Generated with Codex

# Please enter the commit message for your changes. Lines starting with '#' will be ignored.
MESSAGE
EDITOR_COMMENT_COMMIT="$(git -C "$REPO" commit-tree "${BASE}^{tree}" -p "$BASE" <"${MESSAGE_FILE}")"
(
  cd "$REPO"
  expect_fail_with "commit range rejects attribution before Git editor comments" 'Generated with' bash "$CHECKER" commits "${BASE}..${EDITOR_COMMENT_COMMIT}"
  expect_fail_with "pre-push rejects an invalid new ref among multiple refs" 'Generated with' bash "$CHECKER" pre-push origin $'HEAD\t'"${GOOD_HEAD}"$'\trefs/heads/good\t'"${BASE}"$'\nrefs/heads/bad\t'"${EDITOR_COMMENT_COMMIT}"$'\trefs/heads/bad\t'"${ZERO_OID}"
)

# This literal is the CaptainHook action placeholder that the test exercises.
# shellcheck disable=SC2016
CONFIGURED_PRE_PUSH_ACTION='bash tools/quality/check-git-conventions.sh pre-push origin {$STDIN}'
# shellcheck disable=SC2016
CONFIGURED_COMMIT_MSG_ACTION='bash tools/quality/check-git-conventions.sh commit-message \"{$ARG|value-of:MESSAGE_FILE}\"'
if ! grep -Fq -- "$CONFIGURED_COMMIT_MSG_ACTION" "${ROOT}/captainhook.json"; then
  fail "CaptainHook must configure the shared commit-message checker"
fi
if ! grep -Fq -- "$CONFIGURED_PRE_PUSH_ACTION" "${ROOT}/captainhook.json"; then
  fail "CaptainHook must pass its real pre-push refs to the shared checker"
fi
mkdir -p "${REPO}/tools/quality"
cp "$CHECKER" "${REPO}/tools/quality/check-git-conventions.sh"

CAPTAINHOOK_CONFIG="${TEST_TMP}/captainhook.json"
cat >"${CAPTAINHOOK_CONFIG}" <<CONFIG
{
  "commit-msg": {
    "enabled": true,
    "actions": [{"action": "${CONFIGURED_COMMIT_MSG_ACTION}"}]
  },
  "pre-push": {
    "enabled": true,
    "actions": [{"action": "${CONFIGURED_PRE_PUSH_ACTION}"}]
  }
}
CONFIG
(
  cd "$REPO"
  cat >"${REPO}/valid-message.txt" <<'MESSAGE'
feat: Add a valid local message

Ticket: T16
MESSAGE
  if env -u CI CAPTAINHOOK_SKIP_HOOKS=0 "$ROOT/vendor/bin/captainhook" --configuration="$CAPTAINHOOK_CONFIG" --input="${REPO}/valid-message.txt" hook:commit-msg "${REPO}/valid-message.txt" >"${TEST_TMP}/captainhook-output" 2>&1; then
    :
  else
    cat "${TEST_TMP}/captainhook-output" >&2
    fail "CaptainHook must accept a valid commit message"
  fi
  cat >"${REPO}/invalid-message.txt" <<'MESSAGE'
feat: Add a local commit message

Generated with Codex

# Please enter the commit message for your changes. Lines starting with '#' will be ignored.
MESSAGE
  if env -u CI CAPTAINHOOK_SKIP_HOOKS=0 "$ROOT/vendor/bin/captainhook" --configuration="$CAPTAINHOOK_CONFIG" --input="${REPO}/invalid-message.txt" hook:commit-msg "${REPO}/invalid-message.txt" >"${TEST_TMP}/captainhook-output" 2>&1; then
    fail "CaptainHook must reject prohibited attribution in a commit message"
  fi
  grep -Fq 'Generated with' "${TEST_TMP}/captainhook-output" || {
    cat "${TEST_TMP}/captainhook-output" >&2
    fail "CaptainHook must report prohibited commit attribution"
  }

  if env -u CI CAPTAINHOOK_SKIP_HOOKS=0 "$ROOT/vendor/bin/captainhook" --configuration="$CAPTAINHOOK_CONFIG" --input="$PUSH_INPUT" hook:pre-push origin 'git@example.invalid:javmeta.git' >"${TEST_TMP}/captainhook-output" 2>&1; then
    :
  else
    cat "${TEST_TMP}/captainhook-output" >&2
    fail "CaptainHook must accept a valid pre-push range"
  fi
  if env -u CI CAPTAINHOOK_SKIP_HOOKS=0 "$ROOT/vendor/bin/captainhook" --configuration="$CAPTAINHOOK_CONFIG" --input="refs/heads/bad ${EDITOR_COMMENT_COMMIT} refs/heads/bad ${ZERO_OID}" hook:pre-push origin 'git@example.invalid:javmeta.git' >"${TEST_TMP}/captainhook-output" 2>&1; then
    fail "CaptainHook must reject an invalid pre-push range"
  fi
  grep -Fq 'Generated with' "${TEST_TMP}/captainhook-output" || {
    cat "${TEST_TMP}/captainhook-output" >&2
    fail "CaptainHook must report the prohibited attribution from the outgoing range"
  }
)

# A normal merge header is allowed, but prohibited footer attribution is not.
MERGE_SIDE="$(git -C "$REPO" commit-tree "${GOOD_HEAD}^{tree}" -p "$GOOD_HEAD" -m 'fix: Add first merge parent')"
MERGE_MAIN="$(git -C "$REPO" commit-tree "${GOOD_HEAD}^{tree}" -p "$GOOD_HEAD" -m 'fix: Add second merge parent')"
MERGE_TREE="$(git -C "$REPO" rev-parse "${GOOD_HEAD}^{tree}")"
VALID_MERGE="$(printf "Merge branch 'feature/example'\n\nMerge the local example branch.\n" | git -C "$REPO" commit-tree "$MERGE_TREE" -p "$MERGE_SIDE" -p "$MERGE_MAIN")"
INVALID_MERGE="$(printf "Merge branch 'feature/attributed'\n\nCo-authored-by: Tool <tool@example.invalid>\n" | git -C "$REPO" commit-tree "$MERGE_TREE" -p "$MERGE_SIDE" -p "$MERGE_MAIN")"
INVALID_MERGE_HEADER="$(printf "Co-authored-by: Tool <tool@example.invalid>\n" | git -C "$REPO" commit-tree "$MERGE_TREE" -p "$MERGE_SIDE" -p "$MERGE_MAIN")"
(
  cd "$REPO"
  expect_pass "merge commit with a standard merge header" bash "$CHECKER" commits "${GOOD_HEAD}..${VALID_MERGE}"
  grep -Fq 'checked 3 commit(s)' "${TEST_TMP}/output" || fail "merge range must count both parents and the merge commit"
  expect_fail_with "merge commit with prohibited coauthor trailer" 'prohibited AI/coauthor trailer' bash "$CHECKER" commits "${GOOD_HEAD}..${INVALID_MERGE}"
  expect_fail_with "merge commit header with prohibited attribution" 'prohibited AI/coauthor trailer' bash "$CHECKER" commits "${GOOD_HEAD}..${INVALID_MERGE_HEADER}"
  expect_fail_with "pre-push rejects prohibited merge attribution" 'prohibited AI/coauthor trailer' bash "$CHECKER" pre-push origin "refs/heads/merge ${INVALID_MERGE} refs/heads/merge ${GOOD_HEAD}"
)

if grep -E 'git (fetch|pull|push)|curl|wget|gh ' "$CHECKER"; then
  fail "checker must not invoke network-oriented tools or Git transport commands"
fi

printf 'git convention checker tests passed\n'

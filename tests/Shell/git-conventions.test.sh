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
MESSAGE
expect_fail_with "generated-with trailer" 'Generated with' bash "$CHECKER" commit-message "$MESSAGE_FILE"

REPO="${TEST_TMP}/repo"
mkdir -p "$REPO"
git -C "$REPO" init -q
git -C "$REPO" config user.name 'T16 Test'
git -C "$REPO" config user.email 't16-test@example.invalid'
git -C "$REPO" config commit.gpgsign false
git -C "$REPO" commit --allow-empty -q -m 'chore: Create local test base'
BASE="$(git -C "$REPO" rev-parse HEAD)"

cat >"${MESSAGE_FILE}" <<'MESSAGE'
feat: Add a valid trailer

Ticket: T16
MESSAGE
git -C "$REPO" commit --allow-empty -q -F "$MESSAGE_FILE"
git -C "$REPO" commit --allow-empty -q -m 'fix: Keep a second commit local'
(
  cd "$REPO"
  expect_pass "multiple local commits" bash "$CHECKER" commits "${BASE}..HEAD"
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

if grep -E 'git (fetch|pull|push)|curl|wget|gh ' "$CHECKER"; then
  fail "checker must not invoke network-oriented tools or Git transport commands"
fi

printf 'git convention checker tests passed\n'

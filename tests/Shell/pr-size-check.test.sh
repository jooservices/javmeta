#!/usr/bin/env bash
# tools/quality/pr-size-check: warn/fail boundaries, the documented exception,
# and base-ref detection. Bash 3.2 compatible.
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
CHECK="${SCRIPT_DIR}/../../tools/quality/pr-size-check"
TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-pr-size-check.XXXXXX")"
TEST_TMP="$(cd "${TEST_TMP}" && pwd -P)"
trap 'rm -rf "${TEST_TMP}"' EXIT INT TERM

fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

git_quiet() {
  git -c user.name=Test -c user.email=test@example.invalid -c init.defaultBranch=develop "$@" >/dev/null 2>&1
}

# Create a repo with one baseline commit on `develop`, then a `feature` branch
# checked out and n extra files committed ahead of the base.
repo_with_n_files() {
  local dir="$1" n="$2" i
  mkdir -p "${dir}"
  git_quiet -C "${dir}" init
  printf 'base\n' > "${dir}/base.txt"
  git_quiet -C "${dir}" add -A
  git_quiet -C "${dir}" commit -m 'chore: Baseline'
  git_quiet -C "${dir}" checkout -b feature
  for (( i = 1; i <= n; i++ )); do
    printf 'x\n' > "${dir}/file_${i}.txt"
  done
  git_quiet -C "${dir}" add -A
  git_quiet -C "${dir}" commit -m "feat: Add ${n} files"
}

# Run the check inside the repo and capture stdout+stderr.
run_check() {
  local dir="$1"
  shift
  (cd "${dir}" && "$CHECK" "$@") 2>&1
}

# --- Boundaries below the fail threshold: pass, warn only at/above 30 ---
R29="${TEST_TMP}/r29"
repo_with_n_files "${R29}" 29
out="$(run_check "${R29}" --base develop)" || true
[[ "$out" == *'29 changed file(s)'* ]] || fail "29-file summary: ${out}"
[[ "$out" != *'WARNING'* ]] || fail "29 files must not warn: ${out}"

R30="${TEST_TMP}/r30"
repo_with_n_files "${R30}" 30
out="$(run_check "${R30}" --base develop)" || true
[[ "$out" == *'30 changed file(s)'* ]] || fail "30-file summary: ${out}"
[[ "$out" == *'WARNING'* ]] || fail "30 files must warn: ${out}"

R99="${TEST_TMP}/r99"
repo_with_n_files "${R99}" 99
out="$(run_check "${R99}" --base develop)" || true
[[ "$out" == *'99 changed file(s)'* ]] || fail "99-file summary: ${out}"
[[ "$out" == *'WARNING'* ]] || fail "99 files must warn: ${out}"

# --- Fail threshold: 100 files fails without an exception ---
R100="${TEST_TMP}/r100"
repo_with_n_files "${R100}" 100
if run_check "${R100}" --base develop >/dev/null 2>&1; then
  fail '100 files without a reason must fail'
fi
out="$(run_check "${R100}" --base develop)" || true
[[ "$out" == *'100 changed file(s)'* ]] || fail "100-file summary: ${out}"
[[ "$out" == *'FAILED'* ]] || fail "100 files must report FAILED: ${out}"

# --- Documented exception lets 100 files pass and is shown, never silent ---
out="$(run_check "${R100}" --base develop --reason 'mass-rename: split App namespace')" || true
[[ "$out" == *'exception accepted'* ]] || fail "exception must be reported: ${out}"
[[ "$out" == *'mass-rename'* ]] || fail "exception reason must be shown: ${out}"

# An empty reason is not a documented exception and must still fail.
if run_check "${R100}" --base develop --reason '' >/dev/null 2>&1; then
  fail 'an empty reason must not bypass the fail threshold'
fi

# --- Default base detection: origin/develop is chosen when --base is absent ---
DETECT="${TEST_TMP}/detect"
mkdir -p "${DETECT}"
git_quiet -C "${DETECT}" init
printf 'base\n' > "${DETECT}/base.txt"
git_quiet -C "${DETECT}" add -A
git_quiet -C "${DETECT}" commit -m 'chore: Baseline'
git_quiet -C "${DETECT}" checkout -b feature
git -C "${DETECT}" update-ref refs/remotes/origin/develop develop
for i in $(seq 1 100); do
  printf 'x\n' > "${DETECT}/detect_${i}.txt"
done
git_quiet -C "${DETECT}" add -A
git_quiet -C "${DETECT}" commit -m 'feat: Add 100 files'
if run_check "${DETECT}" >/dev/null 2>&1; then
  fail 'default base detection must still fail on 100 files'
fi
out="$(run_check "${DETECT}")" || true
[[ "$out" == *'100 changed file(s)'* ]] || fail "default base summary: ${out}"

# --- Argument validation refuses nonsensical thresholds ---
if run_check "${R100}" --base develop --warn not-a-number >/dev/null 2>&1; then
  fail 'a non-numeric --warn must be refused'
fi
if run_check "${R100}" --base develop --warn 60 --fail 40 >/dev/null 2>&1; then
  fail '--warn above --fail must be refused'
fi

# A missing option value is a usage error with a message, not a silent exit.
out="$(run_check "${R100}" --base 2>&1)" && fail 'a missing --base value must be refused'
[[ "$out" == *'requires a value'* ]] || fail "missing-value message: ${out}"

# The policy thresholds can be lowered but never raised.
if run_check "${R100}" --base develop --warn 31 >/dev/null 2>&1; then
  fail '--warn above the policy limit must be refused'
fi
if run_check "${R100}" --base develop --fail 1000 >/dev/null 2>&1; then
  fail '--fail above the policy limit must be refused'
fi

# Only a documented mass-rename or generated-files exception passes at the limit.
if run_check "${R100}" --base develop --reason 'ordinary feature work' >/dev/null 2>&1; then
  fail 'an ordinary reason must not be accepted as an exception'
fi
out="$(run_check "${R100}" --base develop --reason 'mass-rename: split App namespace')" || fail "a documented mass-rename must pass: ${out}"
[[ "$out" == *'exception accepted'* ]] || fail "exception message: ${out}"

printf 'pr-size-check tests passed\n'

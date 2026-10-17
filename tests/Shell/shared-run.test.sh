#!/usr/bin/env bash
# docker/dev/shared-run guards: live mode and a missing command are refused before anything runs.
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
RUN="${SCRIPT_DIR}/../../docker/dev/shared-run"

fail() { echo "FAIL: $*" >&2; exit 1; }

if output="$(python3 -I "$RUN" --mode live -- true 2>&1)"; then
  fail 'live mode must be refused'
fi
[[ "$output" == *'live is never allowed'* ]] || fail "unexpected live-mode message: $output"

if output="$(python3 -I "$RUN" --mode test 2>&1)"; then
  fail 'a missing command must be refused'
fi
[[ "$output" == *'a command and --mode'* ]] || fail "unexpected missing-command message: $output"

echo 'shared-run guard tests: PASS'

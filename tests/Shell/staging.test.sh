#!/usr/bin/env bash
# Both unattended wrappers delegate to the exact same implementation and require a ref.
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
mkdir -p "$root/.tmp"
fixture="$(mktemp -d "$root/.tmp/staging-test.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT
for target in staging uat; do
  if bash "$root/deploy/staging.sh" "$target" --non-interactive > "$fixture/log" 2>&1; then echo 'FAIL: implicit ref accepted'; exit 1; fi
  grep -q REF "$fixture/log"
  grep -q TARGET_DIR "$fixture/log"
  grep -q REPO_URL "$fixture/log"
done
help="$(bash "$root/deploy/staging.sh" --help)"
grep -q '\[staging|uat\]' <<< "$help"
echo 'OK (3 staging entry-point scenarios)'

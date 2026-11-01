#!/usr/bin/env bash
# Exact-commit CI, backup and secrets gates for the shared native release engine.
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
mkdir -p "$root/.tmp"
fixture="$(mktemp -d "$root/.tmp/production-test.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT
# shellcheck source=deploy/lib/install.sh
source "$root/deploy/lib/install.sh"
fail() { echo "FAIL: $*" >&2; exit 1; }
mkdir -p "$fixture/bin"
cat > "$fixture/bin/gh" <<'STUB'
#!/usr/bin/env bash
[[ "${CI_STATE:-}" != offline ]] || exit 1
[[ "$*" == *commits/exact-sha/check-runs* ]] || exit 2
case "${CI_STATE:-}" in
  empty) exit 0 ;; unrelated) printf 'Other\tcompleted\tsuccess\n'; exit 0 ;;
esac
for name in 'Test gate' 'Secrets gate'; do
  [[ "${CI_STATE:-}" != missing || "$name" != 'Secrets gate' ]] || continue
  state=completed; conclusion=success
  if [[ "$name" == 'Test gate' ]]; then
    case "${CI_STATE:-}" in pending) state=in_progress ;; failed) conclusion=failure ;; skipped) conclusion=skipped ;; neutral) conclusion=neutral ;; esac
  fi
  printf '%s\t%s\t%s\n' "$name" "$state" "$conclusion"
done
STUB
cat > "$fixture/bin/pg_restore" <<'STUB'
#!/usr/bin/env bash
[[ "${BAD_DUMP:-}" != invalid ]] || exit 1
printf ';     dbname: %s\n' "${DUMP_DB:-fixture}"
STUB
cat > "$fixture/bin/php-health" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$STARTUP_LOG"
exit "${STARTUP_HEALTH_EXIT:-0}"
STUB
chmod +x "$fixture/bin/"*
export PATH="$fixture/bin:$PATH"
declare -A answers=([CI_CHECKS]='Test gate,Secrets gate' [CI_REPOSITORY]='fixture/repo' [BACKUP_FILE]="$fixture/dump" [BACKUP_RESTORE_VERIFIED]=1 [DB_DATABASE]=fixture)
answers[PHP_BINARY]="$fixture/bin/php-health"
answers[NODE_ROLE]=primary
export STARTUP_LOG="$fixture/startup.log"
startup_health
grep -q 'artisan health:startup --role=web' "$STARTUP_LOG" || fail 'primary startup health used the wrong role'
answers[NODE_ROLE]=worker
startup_health
grep -q 'artisan health:startup --role=worker' "$STARTUP_LOG" || fail 'worker startup health used the wrong role'
mkdir -p "$fixture/deploy/release" "$fixture/deploy/previous"
ln -s "$fixture/deploy/previous" "$fixture/deploy/current"
set +e
(
  set -Eeuo pipefail
  base="$fixture/deploy"
  previous="$fixture/deploy/previous"
  release="$fixture/deploy/release"
  quiesced=0
  started=$SECONDS
  answers[NODE_ROLE]=primary
  export STARTUP_HEALTH_EXIT=1
  trap rollback ERR
  step 'Startup health on staged release' startup_health
  activate_release
) > "$fixture/rollback.log" 2>&1
rollback_status=$?
set -e
(( rollback_status != 0 )) || fail 'deployment switched current after a failed primary health check'
[[ "$(readlink -f "$fixture/deploy/current")" == "$fixture/deploy/previous" ]] || fail 'failed startup health did not leave current on the previous release'
unset STARTUP_HEALTH_EXIT
skip_ci=0
for state in offline empty unrelated missing pending failed skipped neutral; do
  if CI_STATE="$state" ci_gate exact-sha > "$fixture/log" 2>&1; then fail "accepted CI $state"; fi
done
ci_gate exact-sha
skip_ci=1
CI_STATE=offline ci_gate exact-sha > "$fixture/log"
grep -q SKIP "$fixture/log" || fail 'CI bypass not disclosed'
if backup_gate; then fail 'missing backup accepted'; fi
printf fixture > "$fixture/dump"
backup_gate
if BAD_DUMP=invalid backup_gate; then fail 'invalid backup accepted'; fi
if DUMP_DB=wrong backup_gate; then fail 'wrong database backup accepted'; fi
touch -d '2 hours ago' "$fixture/dump"
if backup_gate; then fail 'stale backup accepted'; fi
touch "$fixture/dump"
answers[BACKUP_RESTORE_VERIFIED]=0
if backup_gate; then fail 'unrehearsed backup accepted'; fi
for entry in production staging; do
  if bash "$root/deploy/$entry.sh" --non-interactive --ref candidate > "$fixture/log" 2>&1; then fail 'missing deployment answers accepted'; fi
  grep -q 'Missing answers' "$fixture/log" || fail 'entry point is not using shared wizard'
done
if bash "$root/deploy/production.sh" < /dev/null > "$fixture/log" 2>&1; then fail 'production wizard accepted missing answers'; fi
grep -q 'Input ended at SERVICE_EMBEDDER_ENABLED' "$fixture/log" || fail 'production entry point did not prompt interactively'
if bash "$root/deploy/install.sh" --non-interactive --set DB_PASSWORD=fixture > "$fixture/log" 2>&1; then fail 'secret argv accepted'; fi
grep -q 'must come from private config' "$fixture/log" || fail 'secret argv not diagnosed'
echo 'OK (22 production CI/backup/entry-point scenarios)'

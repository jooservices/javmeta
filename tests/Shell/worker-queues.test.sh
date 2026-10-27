#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
WORKER_SCRIPT="${SCRIPT_DIR}/../../docker/worker-queues.sh"
BASH_BIN="${BASH_BIN:-bash}"
TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-worker-queues.XXXXXX")"
FAKE_PHP="${TEST_TMP}/fake-php"
FAKE_STATE="${TEST_TMP}/state"
SUPERVISOR_PID=""
export FAKE_STATE

cleanup() {
  if [[ -n "${SUPERVISOR_PID}" ]]; then
    kill -TERM "${SUPERVISOR_PID}" 2>/dev/null || true
    wait "${SUPERVISOR_PID}" 2>/dev/null || true
  fi
  rm -rf "${TEST_TMP}"
}

trap cleanup EXIT INT TERM

mkdir -p "${FAKE_STATE}"
cat > "${FAKE_PHP}" <<'FAKE_PHP_SCRIPT'
#!/usr/bin/env bash
set -euo pipefail

queue=""
for arg in "$@"; do
  case "${arg}" in
    --queue=*) queue="${arg#--queue=}" ;;
  esac
done

state_dir="${FAKE_STATE}"
count_file="${state_dir}/count_${queue}"
count=0
if [[ -f "${count_file}" ]]; then
  count="$(cat "${count_file}")"
fi
count=$((count + 1))
printf '%s\n' "${count}" > "${count_file}"
printf '%s\n' "$@" > "${state_dir}/args_${queue}_$$"
printf '%s|%s|%s\n' "${queue}" "$$" "$(date +%s)" >> "${state_dir}/launches"

if [[ "${FAKE_EXIT_QUEUE:-}" = "${queue}" && "${count}" -eq 1 ]]; then
  exit "${FAKE_EXIT_CODE:-7}"
fi

if [[ "${FAKE_MODE:-hold}" = rapid ]]; then
  exit 9
fi

if [[ "${FAKE_MODE:-hold}" = ignore-term ]]; then
  trap ':' TERM INT
else
  trap 'exit 0' TERM INT
fi

while :; do
  sleep 1
done
FAKE_PHP_SCRIPT
chmod +x "${FAKE_PHP}"

reset_state() {
  rm -rf "${FAKE_STATE}"
  mkdir -p "${FAKE_STATE}"
  SUPERVISOR_PID=""
}

start_supervisor() {
  : > "${FAKE_STATE}/launches"
  JAVMETA_PHP_BIN="${FAKE_PHP}" \
    QUEUE_CONNECTION=database \
    JAVMETA_QUEUE_SLEEP=1 \
    JAVMETA_QUEUE_TRIES=3 \
    JAVMETA_QUEUE_MAX_TIME=60 \
    JAVMETA_QUEUE_MAX_JOBS=500 \
    "${BASH_BIN}" "${WORKER_SCRIPT}" > "${FAKE_STATE}/supervisor.log" 2>&1 &
  SUPERVISOR_PID=$!
}

wait_for_launches() {
  local expected="$1"
  local timeout="$2"
  local started
  local count

  started="$(date +%s)"
  while :; do
    count="$(wc -l < "${FAKE_STATE}/launches" | tr -d ' ')"
    if ((count >= expected)); then
      return 0
    fi
    if (( $(date +%s) - started >= timeout )); then
      echo "Timed out waiting for ${expected} worker launches." >&2
      cat "${FAKE_STATE}/supervisor.log" >&2 || true
      return 1
    fi
    sleep 1
  done
}

wait_for_log() {
  local pattern="$1"
  local timeout="$2"
  local started

  started="$(date +%s)"
  while :; do
    if grep -q "${pattern}" "${FAKE_STATE}/supervisor.log" 2>/dev/null; then
      return 0
    fi
    if (( $(date +%s) - started >= timeout )); then
      echo "Timed out waiting for log pattern: ${pattern}" >&2
      cat "${FAKE_STATE}/supervisor.log" >&2 || true
      return 1
    fi
    sleep 1
  done
}

stop_supervisor() {
  local status=0

  if [[ -n "${SUPERVISOR_PID}" ]]; then
    kill -TERM "${SUPERVISOR_PID}" 2>/dev/null || true
    if wait "${SUPERVISOR_PID}"; then
      status=0
    else
      status=$?
    fi
    SUPERVISOR_PID=""
  fi

  return "${status}"
}

assert_eq() {
  local expected="$1"
  local actual="$2"
  local message="$3"

  if [[ "${expected}" != "${actual}" ]]; then
    echo "FAIL: ${message}: expected=${expected} actual=${actual}" >&2
    return 1
  fi
}

assert_contains_arg() {
  local expected="$1"
  local args_file="$2"

  grep -q "^${expected}$" "${args_file}"
}

test_starts_one_worker_per_queue_slot() {
  reset_state
  export JAVMETA_QUEUE_NAMES=alpha,beta JAVMETA_WORKERS_PER_QUEUE=2 JAVMETA_QUEUE_TIMEOUT=180 FAKE_MODE=hold
  start_supervisor
  wait_for_launches 6 5
  assert_eq 2 "$(awk -F'|' '$1 == "alpha" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'alpha worker count'
  assert_eq 2 "$(awk -F'|' '$1 == "beta" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'beta worker count'
  assert_eq 2 "$(awk -F'|' '$1 == "observability" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'observability worker count'
  stop_supervisor
}

test_does_not_duplicate_explicit_observability_queue() {
  reset_state
  export JAVMETA_QUEUE_NAMES=alpha,observability JAVMETA_WORKERS_PER_QUEUE=1 FAKE_MODE=hold
  start_supervisor
  wait_for_launches 2 5
  assert_eq 1 "$(awk -F'|' '$1 == "observability" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'observability worker count'
  stop_supervisor
}

test_passes_timeout_default_and_override() {
  reset_state
  unset JAVMETA_QUEUE_TIMEOUT
  export JAVMETA_QUEUE_NAMES=alpha JAVMETA_WORKERS_PER_QUEUE=1 FAKE_MODE=hold
  start_supervisor
  wait_for_launches 1 5
  args_file="$(find "${FAKE_STATE}" -name 'args_alpha_*' -type f | head -1)"
  assert_contains_arg '--timeout=180' "${args_file}"
  stop_supervisor

  reset_state
  export JAVMETA_QUEUE_TIMEOUT=321
  start_supervisor
  wait_for_launches 1 5
  args_file="$(find "${FAKE_STATE}" -name 'args_alpha_*' -type f | head -1)"
  assert_contains_arg '--timeout=321' "${args_file}"
  stop_supervisor
  unset JAVMETA_QUEUE_TIMEOUT
}

test_restarts_only_failed_worker() {
  reset_state
  export JAVMETA_QUEUE_NAMES=alpha,beta JAVMETA_WORKERS_PER_QUEUE=1 FAKE_MODE=hold
  export FAKE_EXIT_QUEUE=alpha FAKE_EXIT_CODE=7
  start_supervisor
  wait_for_launches 3 5
  beta_pid="$(awk -F'|' '$1 == "beta" { print $2; exit }' "${FAKE_STATE}/launches")"
  wait_for_launches 4 7
  assert_eq 2 "$(awk -F'|' '$1 == "alpha" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'failed worker restart count'
  assert_eq 1 "$(awk -F'|' '$1 == "beta" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'healthy worker restart count'
  assert_eq 1 "$(awk -F'|' '$1 == "observability" { count++ } END { print count + 0 }' "${FAKE_STATE}/launches")" 'observability worker restart count'
  current_beta_pid="$(awk -F'|' '$1 == "beta" { print $2; exit }' "${FAKE_STATE}/launches")"
  assert_eq "${beta_pid}" "${current_beta_pid}" 'healthy worker pid'
  stop_supervisor
  unset FAKE_EXIT_QUEUE FAKE_EXIT_CODE
}

test_backoff_grows() {
  reset_state
  export JAVMETA_QUEUE_NAMES=alpha JAVMETA_WORKERS_PER_QUEUE=1 FAKE_MODE=rapid
  start_supervisor
  wait_for_log 'backoff=2s' 5
  wait_for_log 'backoff=4s' 8
  grep -q 'worker exited queue=alpha slot=1 code=9 restarts=1 backoff=2s' "${FAKE_STATE}/supervisor.log"
  grep -q 'worker exited queue=alpha slot=1 code=9 restarts=2 backoff=4s' "${FAKE_STATE}/supervisor.log"
  stop_supervisor
  unset FAKE_MODE
}

test_sigterm_exits_zero() {
  reset_state
  export JAVMETA_QUEUE_NAMES=alpha,beta JAVMETA_WORKERS_PER_QUEUE=1 FAKE_MODE=hold
  start_supervisor
  wait_for_launches 3 5
  stop_supervisor
}

test_sigterm_kills_term_ignoring_child() {
  reset_state
  export JAVMETA_QUEUE_NAMES=alpha,observability JAVMETA_WORKERS_PER_QUEUE=1 FAKE_MODE=ignore-term JAVMETA_QUEUE_STOP_GRACE=1
  start_supervisor
  wait_for_launches 2 5
  child_pid="$(awk -F'|' 'NR == 1 { print $2; exit }' "${FAKE_STATE}/launches")"
  stop_supervisor
  if kill -0 "${child_pid}" 2>/dev/null; then
    echo 'FAIL: TERM-ignoring worker still exists after stop grace.' >&2
    return 1
  fi
  unset JAVMETA_QUEUE_STOP_GRACE
}

test_starts_one_worker_per_queue_slot
test_does_not_duplicate_explicit_observability_queue
test_passes_timeout_default_and_override
test_restarts_only_failed_worker
test_backoff_grows
test_sigterm_exits_zero
test_sigterm_kills_term_ignoring_child

echo 'worker-queues shell tests: PASS'

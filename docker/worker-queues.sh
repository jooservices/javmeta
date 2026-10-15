#!/usr/bin/env bash
# One process per Laravel crawl queue name (1 worker / queue on this instance).
set -euo pipefail

CONNECTION="${QUEUE_CONNECTION:-database}"
QUEUES="${JAVMETA_QUEUE_NAMES:-listing,detail,performer_listing,performer_detail,gallery}"
SLEEP="${JAVMETA_QUEUE_SLEEP:-3}"
TRIES="${JAVMETA_QUEUE_TRIES:-3}"
MAX_TIME="${JAVMETA_QUEUE_MAX_TIME:-3600}"
MAX_JOBS="${JAVMETA_QUEUE_MAX_JOBS:-500}"
WORKERS_PER_QUEUE="${JAVMETA_WORKERS_PER_QUEUE:-1}"
TIMEOUT="${JAVMETA_QUEUE_TIMEOUT:-180}"
STOP_GRACE="${JAVMETA_QUEUE_STOP_GRACE:-200}"
PHP_BIN="${JAVMETA_PHP_BIN:-php}"

IFS=',' read -r -a QUEUE_LIST <<< "${QUEUES}"

worker_queues=()
worker_slots=()
worker_pids=()
worker_started_at=()
worker_restarts=()
worker_backoffs=()
worker_count=0
shutting_down=0

now_seconds() {
  date +%s
}

start_worker() {
  local index="$1"
  local queue="${worker_queues[$index]}"
  local slot="${worker_slots[$index]}"

  echo "Starting worker instance queue=${queue} slot=${slot}/${WORKERS_PER_QUEUE}"
  "${PHP_BIN}" artisan queue:work "${CONNECTION}" \
    --queue="${queue}" \
    --sleep="${SLEEP}" \
    --tries="${TRIES}" \
    --max-time="${MAX_TIME}" \
    --max-jobs="${MAX_JOBS}" \
    --timeout="${TIMEOUT}" \
    --no-interaction &
  worker_pids[index]=$!
  worker_started_at[index]="$(now_seconds)"
}

children_alive() {
  local index=0

  while ((index < worker_count)); do
    if kill -0 "${worker_pids[$index]}" 2>/dev/null; then
      return 0
    fi
    index=$((index + 1))
  done

  return 1
}

stop_children() {
  local index=0
  local deadline
  local now

  while ((index < worker_count)); do
    kill -TERM "${worker_pids[$index]}" 2>/dev/null || true
    index=$((index + 1))
  done

  deadline=$(( $(now_seconds) + STOP_GRACE ))
  while children_alive; do
    now="$(now_seconds)"
    if ((now >= deadline)); then
      break
    fi
    sleep 1 || true
  done

  index=0
  while ((index < worker_count)); do
    if kill -0 "${worker_pids[$index]}" 2>/dev/null; then
      kill -KILL "${worker_pids[$index]}" 2>/dev/null || true
    fi
    index=$((index + 1))
  done

  index=0
  while ((index < worker_count)); do
    if wait "${worker_pids[$index]}"; then
      :
    else
      :
    fi
    index=$((index + 1))
  done
}

shutdown() {
  if ((shutting_down == 1)); then
    return
  fi

  shutting_down=1
  trap - INT TERM
  stop_children
  exit 0
}

trap shutdown INT TERM

for queue in "${QUEUE_LIST[@]}"; do
  queue="$(echo "${queue}" | xargs)"
  [[ -z "${queue}" ]] && continue

  for ((slot = 1; slot <= WORKERS_PER_QUEUE; slot++)); do
    worker_queues[worker_count]="${queue}"
    worker_slots[worker_count]="${slot}"
    worker_restarts[worker_count]=0
    worker_backoffs[worker_count]=2
    start_worker "${worker_count}"
    worker_count=$((worker_count + 1))
  done
done

while :; do
  for ((index = 0; index < worker_count; index++)); do
    if kill -0 "${worker_pids[$index]}" 2>/dev/null; then
      continue
    fi

    exit_code=0
    if wait "${worker_pids[$index]}"; then
      exit_code=0
    else
      exit_code=$?
    fi

    runtime=$(( $(now_seconds) - worker_started_at[index] ))
    if ((runtime > 60)); then
      worker_restarts[index]=0
      worker_backoffs[index]=2
    fi

    worker_restarts[index]=$((worker_restarts[index] + 1))
    backoff="${worker_backoffs[$index]}"
    printf 'worker exited queue=%s slot=%s code=%s restarts=%s backoff=%ss\n' \
      "${worker_queues[$index]}" \
      "${worker_slots[$index]}" \
      "${exit_code}" \
      "${worker_restarts[$index]}" \
      "${backoff}"

    sleep "${backoff}" || true
    start_worker "${index}"

    next_backoff=$((backoff * 2))
    if ((next_backoff > 30)); then
      next_backoff=30
    fi
    worker_backoffs[index]="${next_backoff}"
  done

  sleep 1 || true
done

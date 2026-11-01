#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

: "${JAVMETA_APP_DIR:?Set the application directory}"
: "${JAVMETA_REPO_URL:?Set the repository URL}"
: "${JAVMETA_BRANCH:?Set the ref to deploy}"
: "${JAVMETA_COMPOSE_FILE:=docker-compose.yml}"
: "${JAVMETA_PHP_SERVICE:=api}"
: "${JAVMETA_WORKER_INSTANCES:=1}"
: "${JAVMETA_READY_RETRIES:=40}"
: "${JAVMETA_READY_INTERVAL:=3}"
: "${JAVMETA_FLARESOLVERR_HEALTH_URL:=http://127.0.0.1:8191/}"

# shellcheck disable=SC1091
source "${SCRIPT_DIR}/repository.sh"

trap 'printf "deploy: failed at line %s: %s\n" "$LINENO" "$BASH_COMMAND" >&2' ERR

require_positive_integer() {
  local name="$1"
  local value="$2"

  [[ "${value}" =~ ^[1-9][0-9]*$ ]] || die "${name} must be a positive integer"
}

crawler_compose() {
  docker compose \
    --project-directory "${JAVMETA_APP_DIR}" \
    --env-file "${JAVMETA_APP_DIR}/.env" \
    --file "${JAVMETA_APP_DIR}/${JAVMETA_COMPOSE_FILE}" \
    --profile app \
    --profile flare \
    "$@"
}

running_count() {
  local service="$1"

  crawler_compose ps --status running -q "${service}" | wc -l | tr -d '[:space:]'
}

wait_for_running() {
  local service="$1"
  local expected="$2"
  local running

  for ((attempt = 1; attempt <= JAVMETA_READY_RETRIES; attempt++)); do
    running="$(running_count "${service}")"
    if [[ "${running}" == "${expected}" ]]; then
      return
    fi
    sleep "${JAVMETA_READY_INTERVAL}"
  done

  die "${service} did not reach ${expected} running container(s)"
}

wait_for_flaresolverr() {
  for ((attempt = 1; attempt <= JAVMETA_READY_RETRIES; attempt++)); do
    if curl --fail --silent --show-error --max-time 5 \
      "${JAVMETA_FLARESOLVERR_HEALTH_URL}" >/dev/null 2>&1; then
      return
    fi
    sleep "${JAVMETA_READY_INTERVAL}"
  done

  die 'FlareSolverr did not become reachable at the configured health endpoint'
}

wait_for_embedder() {
  for ((attempt = 1; attempt <= JAVMETA_READY_RETRIES; attempt++)); do
    # shellcheck disable=SC2016
    if crawler_compose exec -T worker php -r '
      $url = rtrim((string) getenv("EMBEDDER_URL"), "/") . "/healthz";
      $handle = curl_init($url);
      curl_setopt_array($handle, [
          CURLOPT_CONNECTTIMEOUT => 5,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_TIMEOUT => 10,
      ]);
      $body = curl_exec($handle);
      $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
      curl_close($handle);
      exit($body !== false && $status >= 200 && $status < 300 ? 0 : 1);
    ' >/dev/null 2>&1; then
      return
    fi
    sleep "${JAVMETA_READY_INTERVAL}"
  done

  die 'control embedder did not become reachable from the crawler worker'
}

validate_crawler_environment() {
  local environment_file="${JAVMETA_APP_DIR}/.env"

  if ! grep -Eq '^CRAWLERX_FLARESOLVERR_REQUIRED=(true|1|yes)$' "${environment_file}"; then
    die 'crawler deployment requires CRAWLERX_FLARESOLVERR_REQUIRED=true in .env'
  fi

  if ! grep -Eq '^EMBEDDER_URL=[^[:space:]]+$' "${environment_file}"; then
    die 'crawler deployment requires EMBEDDER_URL to point to the control embedder'
  fi

  if grep -Eq '^EMBEDDER_URL=(http://)?embedder:8000/?$' "${environment_file}"; then
    die 'crawler EMBEDDER_URL must not use the local embedder service name'
  fi
}

require_command git
require_command docker
require_command curl
require_positive_integer JAVMETA_WORKER_INSTANCES "${JAVMETA_WORKER_INSTANCES}"
require_positive_integer JAVMETA_READY_RETRIES "${JAVMETA_READY_RETRIES}"
require_positive_integer JAVMETA_READY_INTERVAL "${JAVMETA_READY_INTERVAL}"

sync_repository
prepare_environment
validate_crawler_environment

if [[ "$(running_count worker)" != "0" ]]; then
  die 'running worker containers found; drain and stop them before deployment'
fi

export_sentry_release
crawler_compose config --quiet
crawler_compose build "${JAVMETA_PHP_SERVICE}"
crawler_compose run --rm --no-deps --entrypoint composer "${JAVMETA_PHP_SERVICE}" \
  install --no-dev --no-interaction --prefer-dist --optimize-autoloader
crawler_compose run --rm --no-deps --entrypoint php "${JAVMETA_PHP_SERVICE}" artisan optimize:clear

crawler_compose up -d --force-recreate flaresolverr
wait_for_running flaresolverr 1
wait_for_flaresolverr

crawler_compose up -d --force-recreate --scale "worker=${JAVMETA_WORKER_INSTANCES}" worker
wait_for_running worker "${JAVMETA_WORKER_INSTANCES}"
wait_for_embedder

printf 'deploy: crawler services are healthy; workers=%s; control services were not started\n' \
  "${JAVMETA_WORKER_INSTANCES}"

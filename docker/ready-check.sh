#!/usr/bin/env bash
# Verify required runtime services are reachable before starting the app.
# Required (fail -> container down): DB, Mongo, Elasticsearch, OpenObserve
# (when enabled), and FlareSolverr when CRAWLERX_FLARESOLVERR_REQUIRED=true.
# Services are checked against the endpoints configured via env, so external
# deployments work the same as local Docker.
set -uo pipefail

RETRIES="${JAVMETA_READY_RETRIES:-40}"
INTERVAL="${JAVMETA_READY_INTERVAL:-3}"

failures=()

tcp_check() {
  php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $s, 3) ? 0 : 1);' "$1" "$2" 2>/dev/null
}

parse_endpoint() {
  php -r '
    $raw = (string) ($argv[1] ?? "");
    if (preg_match("#^(https?|mongodb)://#i", $raw)) {
      $u = parse_url($raw);
      $host = $u["host"] ?? "";
      $port = $u["port"] ?? (($u["scheme"] ?? "") === "https" ? 443 : 80);
    } else {
      [$host, $port] = array_pad(explode(":", $raw, 2), 2, "");
    }
    echo trim($host) . " " . trim($port);
  ' "$1"
}

check_tcp() {
  local name="$1" endpoint="$2"
  local host port
  read -r host port < <(parse_endpoint "$endpoint")
  if [[ -z "$host" || -z "$port" ]]; then
    failures+=("$name (bad endpoint: $endpoint)")
    return
  fi
  if ! tcp_check "$host" "$port"; then
    failures+=("$name ($host:$port)")
  fi
}

attempt() {
  failures=()

  if [[ -n "${DB_HOST:-}" ]]; then
    check_tcp "db-${DB_CONNECTION:-pgsql}" "${DB_HOST}:${DB_PORT:-5432}"
  fi

  if [[ -n "${MONGO_URI:-}" ]]; then
    check_tcp "mongo" "$MONGO_URI"
  else
    check_tcp "mongo" "${MONGO_HOST:-mongo}:${MONGO_PORT:-27017}"
  fi

  check_tcp "elasticsearch" "${ELASTICSEARCH_HOST:-http://elasticsearch:9200}"

  if [[ "${OPENOBSERVE_ENABLED:-true}" == "true" ]]; then
    check_tcp "openobserve" "${OPENOBSERVE_URL:-http://openobserve:5080}"
  fi

  if [[ "${CRAWLERX_FLARESOLVERR_REQUIRED:-true}" == "true" ]]; then
    check_tcp "flaresolverr" "${CRAWLERX_FLARESOLVERR_URL:-http://flaresolverr:8191/v1}"
  fi
}

for ((i = 1; i <= RETRIES; i++)); do
  attempt
  if [[ ${#failures[@]} -eq 0 ]]; then
    echo "ready-check: all required services reachable"
    exec "$@"
  fi
  echo "ready-check ($i/${RETRIES}): waiting for: ${failures[*]:-}"
  sleep "$INTERVAL"
done

echo "ready-check: FAILED after ${RETRIES} tries — unreachable: ${failures[*]:-}" >&2
exit 1

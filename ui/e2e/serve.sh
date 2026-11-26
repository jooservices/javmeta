#!/usr/bin/env bash
# Rebuilds the throwaway e2e database, seeds it and starts the Laravel API on :8000 (pid in .tmp/e2e-php.pid).
# Needs E2E_DB_PASSWORD. Usage: ui/e2e/serve.sh start|stop
set -euo pipefail
cd "$(dirname "$0")/../.."
pidfile=.tmp/e2e-php.pid
mkdir -p .tmp
stop() { [ -f "$pidfile" ] && kill "$(cat "$pidfile")" 2>/dev/null || true; rm -f "$pidfile"; }
case "${1:-start}" in
  stop) stop ;;
  start)
    stop
    . ui/e2e/env.sh
    PGPASSWORD="$E2E_DB_PASSWORD" psql -q -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d postgres -c "drop database if exists $DB_DATABASE" -c "create database $DB_DATABASE" -c "alter database $DB_DATABASE set timezone to 'UTC'"
    php artisan migrate --force >/dev/null
    php ui/e2e/seed.php
    php -S 127.0.0.1:8000 -t public public/index.php >.tmp/e2e-php.log 2>&1 &
    echo $! >"$pidfile"
    for _ in $(seq 1 30); do curl -sf -o /dev/null "http://127.0.0.1:8000/api/v2/openapi.json" && exit 0; sleep 0.5; done
    echo "API did not start" >&2; exit 1 ;;
esac

#!/usr/bin/env bash
# Runtime-only admin credentials are used solely to repair the requested timezone.
# shellcheck disable=SC2154
repair_timezone() (
  set +x
  local ADMIN_USERNAME ADMIN_PASSWORD
  printf 'PostgreSQL timezone repair admin username: ' >&2
  IFS= read -r ADMIN_USERNAME <&3 || return 1
  printf 'Admin password (hidden, memory only): ' >&2
  IFS= read -rs ADMIN_PASSWORD <&3 || return 1
  printf '\n' >&2
  [[ -n "$ADMIN_USERNAME" && -n "$ADMIN_PASSWORD" ]] || return 1
  export DB_DATABASE="${answers[DB_DATABASE]}"
  PGHOST="${answers[DB_HOST]}" PGPORT="${answers[DB_PORT]}" PGDATABASE="${answers[PROVISION_ADMIN_DATABASE]}" \
    PGUSER="$ADMIN_USERNAME" PGPASSWORD="$ADMIN_PASSWORD" PGSSLMODE="${answers[DB_SSLMODE]}" \
    psql -X -f "$root/deploy/production/provision/timezone.sql" >/dev/null 2>&1
)

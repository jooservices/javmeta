#!/usr/bin/env bash
# The two JavMeta environments on the WSL workstation and every name that must differ between them.
# Source it and call `load_environment staging|uat`; run it as `deploy/environments.sh <target>` to print the table.
# Nothing here changes anything: it only sets variables.

load_environment() {
  local target="$1"
  local root="${WORKSPACES_ROOT:-${HOME}/Workspaces}"
  case "${target}" in
    staging)
      JAVMETA_ENV=staging
      APP_DIR_DEFAULT="${root}/JOOservices/projects/javmeta"
      POSTGRES_DATABASE_DEFAULT=javmeta
      MONGO_DATABASE_DEFAULT=javmeta
      ELASTICSEARCH_PREFIX_DEFAULT=javmeta
      REDIS_PREFIX_DEFAULT=javmeta_
      WEB_PORT_DEFAULT=18485
      WEB_HOST_DEFAULT=jav.soulevil.com
      UNIT_PREFIX=javmeta
      ;;
    uat)
      JAVMETA_ENV=uat
      APP_DIR_DEFAULT="${root}/JOOservices/projects/javmeta-uat"
      POSTGRES_DATABASE_DEFAULT=javmeta_uat
      MONGO_DATABASE_DEFAULT=javmeta_uat
      ELASTICSEARCH_PREFIX_DEFAULT=javmeta_uat
      REDIS_PREFIX_DEFAULT=javmeta_uat_
      WEB_PORT_DEFAULT=18486
      WEB_HOST_DEFAULT=jav-uat.soulevil.com
      UNIT_PREFIX=javmeta-uat
      ;;
    *) return 1 ;;
  esac
  HORIZON_UNIT="${UNIT_PREFIX}-horizon.service"
  SCHEDULER_UNIT="${UNIT_PREFIX}-scheduler.service"
  BACKUP_UNIT_PREFIX="${UNIT_PREFIX}-backup"
  STATE_DIR_DEFAULT="${root}/.tmp/${UNIT_PREFIX}-deploy"
}

print_environments() {
  local t
  printf '%-8s %-45s %-14s %-14s %-14s %-16s %-6s %-22s %s\n' target app_dir postgres mongo elasticsearch redis_prefix port host units
  for t in staging uat; do
    load_environment "${t}"
    printf '%-8s %-45s %-14s %-14s %-14s %-16s %-6s %-22s %s\n' "${t}" "${APP_DIR_DEFAULT}" "${POSTGRES_DATABASE_DEFAULT}" "${MONGO_DATABASE_DEFAULT}" "${ELASTICSEARCH_PREFIX_DEFAULT}" "${REDIS_PREFIX_DEFAULT}" "${WEB_PORT_DEFAULT}" "${WEB_HOST_DEFAULT}" "${HORIZON_UNIT},${SCHEDULER_UNIT}"
  done
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  set -euo pipefail
  case "${1:-}" in
    '') print_environments ;;
    staging|uat) load_environment "$1"; print_environments | awk -v t="$1" 'NR==1 || $1==t' ;;
    *) printf 'Usage: %s [staging|uat]\n' "$0" >&2; exit 2 ;;
  esac
fi

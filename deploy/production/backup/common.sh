#!/usr/bin/env bash
# Shared backup configuration. Never execute the env file as shell code.
set +x
set -Eeuo pipefail
umask 077
backup_scripts="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
php_bin="${BACKUP_PHP_BIN:-php8.5}"
env_file="${BACKUP_ENV_FILE:-}"
backup_dir="${BACKUP_DIR:-}"
keep="${BACKUP_KEEP:-}"
manifest="${BACKUP_MANIFEST:-}"
fail() { printf 'BACKUP FAILED: %s; previous success manifest retained; check javmeta:backup-status.\n' "$*" >&2; exit 1; }
load_config() {
  [[ -f "$env_file" ]] || fail 'Provide --env-file or BACKUP_ENV_FILE.'
  config_tmp="$(mktemp)"
  if ! "$php_bin" "$backup_scripts/support.php" env "$env_file" > "$config_tmp" 2>/dev/null; then
    rm -f -- "$config_tmp"
    fail 'Cannot parse backup env configuration.'
  fi
  # Only whitelisted, shell-escaped values emitted by support.php are sourced.
  source "$config_tmp"
  rm -f -- "$config_tmp"
  backup_dir="${backup_dir:-${CFG_BACKUP_DIR:-$(dirname -- "$env_file")/backups}}"
  manifest="${manifest:-${CFG_JAVMETA_BACKUP_MANIFEST:-$(dirname -- "$env_file")/storage/app/private/backups/latest.json}}"
  keep="${keep:-${CFG_BACKUP_KEEP:-14}}"
  [[ "$keep" =~ ^[1-9][0-9]{0,3}$ ]] || fail 'Retention must be an integer from 1 to 9999.'
  [[ "$backup_dir" == /* && "$backup_dir" != / && "$manifest" == /* ]] || fail 'Use absolute backup and manifest paths.'
  [[ -n "${CFG_DB_HOST:-}" && -n "${CFG_DB_DATABASE:-}" && -n "${CFG_DB_USERNAME:-}" ]] || fail 'Database connection fields are missing.'
  # libpq reads connection fields from the environment, never argv or diagnostic output.
  export PGHOST="$CFG_DB_HOST" PGPORT="${CFG_DB_PORT:-5432}" PGUSER="$CFG_DB_USERNAME"
  export PGPASSWORD="${CFG_DB_PASSWORD:-}" PGSSLMODE="${CFG_DB_SSLMODE:-require}" PGDATABASE="$CFG_DB_DATABASE"
  [[ "$PGSSLMODE" =~ ^(require|verify-ca|verify-full)$ ]] || fail 'PostgreSQL TLS is required.'
  export PGCONNECT_TIMEOUT=15
}

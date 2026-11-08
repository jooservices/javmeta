#!/usr/bin/env bash
# Run as Redis administrator. REDISCLI_AUTH is the admin secret; never use -a.
set -euo pipefail
set +x
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${XCRAWLER_REDIS_PASSWORD:-}" ]]; then
  read -rs -p 'XCrawler Redis password: ' XCRAWLER_REDIS_PASSWORD
  printf '\n' >&2
fi
[[ -n "${XCRAWLER_REDIS_PASSWORD}" ]] || exit 1
redis_cli() {
  redis-cli --no-auth-warning --raw -e \
    -h "${REDIS_HOST:-127.0.0.1}" -p "${REDIS_PORT:-6379}" \
    --user "${REDIS_ADMIN_USER:-default}" "$@"
}
# Send the password hash over stdin: neither password nor hash enters argv.
hash="$(printf '%s' "$XCRAWLER_REDIS_PASSWORD" | sha256sum | cut -d ' ' -f1)"
mapfile -t lines < <(sed '/^#/d; /^$/d' "${root}/redis-acl.txt")
read -ra rules <<< "${lines[0]}"
printf '#%s' "$hash" | redis_cli -x ACL SETUSER xcrawler "${rules[@]}"
unset hash XCRAWLER_REDIS_PASSWORD
# Persist with ACL SAVE only after the operator verifies the VM uses aclfile; see runbook.

#!/usr/bin/env bash
# Refresh only the application systemd units; OS packages and services are untouched.
set -Eeuo pipefail
set +x

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
manifest="$root/deploy/manifest.json"
mode=apply
case "${1:-}" in
  '') ;;
  --dry-run) mode=dry-run ;;
  *) printf 'Usage: %s [--dry-run]\n' "${BASH_SOURCE[0]}" >&2; exit 2 ;;
esac

command -v jq >/dev/null || { echo 'Install jq before refreshing units.' >&2; exit 1; }
if [[ "$mode" == apply && "$EUID" != 0 ]]; then
  echo 'Run unit refresh as root.' >&2
  exit 1
fi

node_value() { jq -er --arg key "$1" '.node[$key]' "$manifest"; }
: "${NODE_ROLE:?}" "${TARGET_DIR:?}" "${SERVICE_USER:?}" "${UNIT_PREFIX:?}" "${GATEKEEPER_ADDRESS:?}" "${UI_HOST:?}" "${CC_HOST:?}" "${PHP_BINARY:?}" "${EMBEDDER_BIND:?}" "${EMBEDDER_PORT:?}"
[[ "$TARGET_DIR" =~ ^/[a-zA-Z0-9_./-]+$ && "$TARGET_DIR" != / ]] || { echo 'Invalid TARGET_DIR for a systemd unit.' >&2; exit 1; }
[[ "$SERVICE_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$SERVICE_USER" != root ]] || { echo 'SERVICE_USER must be a non-root system account name.' >&2; exit 1; }
[[ "$PHP_BINARY" =~ ^/[a-zA-Z0-9_./+-]+$ ]] || { echo 'PHP_BINARY must be an absolute executable path.' >&2; exit 1; }
[[ "$EMBEDDER_BIND" =~ ^[a-zA-Z0-9_.:-]+$ && "$EMBEDDER_PORT" =~ ^[0-9]{1,5}$ ]] || { echo 'Invalid embedder bind address or port.' >&2; exit 1; }
(( 10#$EMBEDDER_PORT > 0 && 10#$EMBEDDER_PORT <= 65535 )) || { echo 'EMBEDDER_PORT must be between 1 and 65535.' >&2; exit 1; }

# shellcheck source=deploy/lib/node-template.sh
source "$root/deploy/lib/node-template.sh"
sync_node_units \
  "$root/deploy/production/node" \
  "$(node_value unit_dir)" \
  "$NODE_ROLE" \
  "$UNIT_PREFIX" \
  "$mode"

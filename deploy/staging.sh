#!/usr/bin/env bash
# Same release engine; environment names are labels, never implicit refs or targets.
set -Eeuo pipefail
case "${1:-}" in
  staging|uat) shift ;;
  --help|-h) printf 'staging.sh [staging|uat] --config private.json --ref REF [--apply]\n' ;;
esac
exec bash "$(dirname -- "${BASH_SOURCE[0]}")/install.sh" --non-interactive "$@"

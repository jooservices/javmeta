#!/usr/bin/env bash
# Interactive production entry point; use --non-interactive for automation.
set -Eeuo pipefail
exec bash "$(dirname -- "${BASH_SOURCE[0]}")/install.sh" "$@"

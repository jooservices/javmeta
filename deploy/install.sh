#!/usr/bin/env bash
# Operator-only install/deploy wizard. No changes unless --apply is supplied.
set -Eeuo pipefail
set +x
umask 027
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=deploy/lib/install.sh
source "$root/deploy/lib/install.sh"
# shellcheck source=deploy/lib/node-template.sh
source "$root/deploy/lib/node-template.sh"
# shellcheck source=deploy/lib/prerequisites.sh
source "$root/deploy/lib/prerequisites.sh"
# shellcheck source=deploy/lib/provision.sh
source "$root/deploy/lib/provision.sh"
install_main "$@"

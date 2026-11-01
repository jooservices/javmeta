#!/usr/bin/env bash
# Native installation is explicit; WSL proof skips all OS changes.
# shellcheck disable=SC2154
prerequisites() {
  local key missing=() started=$SECONDS
  if (( ! skip_os )); then
    # shellcheck source=/dev/null
    source /etc/os-release
    [[ "$ID" == ubuntu && "${VERSION_ID%%.*}" -ge "$(jq -r '.node.minimum_ubuntu' "$manifest")" ]] || die 'Ubuntu 24.04+ is required.'
  fi
  if [[ "${answers[INSTALL_NODE]}" == 1 ]]; then
    (
      for key in "${!answers[@]}"; do export "$key=${answers[$key]}"; done
      sudo --preserve-env=TARGET_DIR,SERVICE_USER,UNIT_PREFIX,GATEKEEPER_ADDRESS,UI_HOST,CC_HOST,PHP_BINARY,FPM_UNIT,NGINX_UNIT,NODE_ROLE,EMBEDDER_BIND,EMBEDDER_PORT bash "$root/deploy/production/node/install-node.sh" >/dev/null 2>&1
    ) || die 'Installer failed; inspect prerequisites privately.'
  fi
  for key in git php composer node psql; do command -v "$key" >/dev/null || missing+=("$key"); done
  (( ${#missing[@]} == 0 )) || die "Missing prerequisites: ${missing[*]}"
  log "OK: installer ($((SECONDS-started))s), prerequisite commands verified"
}

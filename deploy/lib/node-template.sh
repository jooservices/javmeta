#!/usr/bin/env bash
# Render only known placeholders, without eval or sed replacement interpretation.
render_node_template() {
  local source_file="$1" destination="$2" key value content
  valid_node_hostname "$UI_HOST" && valid_node_hostname "$CC_HOST" || return 1
  content="$(cat "$source_file")"
  for key in TARGET_DIR SERVICE_USER UNIT_PREFIX GATEKEEPER_ADDRESS UI_HOST CC_HOST PHP_BINARY EMBEDDER_BIND EMBEDDER_PORT; do
    value="${!key}"
    [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || return 1
    content="${content//@$key@/$value}"
  done
  [[ "$content" != *@[A-Z_]*@* ]] || return 1
  printf '%s\n' "$content" > "$destination"
  chmod 644 "$destination"
}

configure_node_role() {
  local role="$1" target="$2" unit_dir="$3" unit dropin
  shift 3
  for unit in "$@"; do
    dropin="$unit_dir/$unit.service.d/worker-role.conf"
    if [[ "$role" == worker ]]; then
      install -d "$(dirname -- "$dropin")"
      printf '[Unit]\nConditionPathExists=%s/shared/primary-role\n' "$target" > "$dropin"
      node_control disable --now "$unit"
    else
      rm -f "$dropin"
    fi
  done
}

node_control() {
  if [[ "${WIZARD_NO_SYSTEMD:-0}" == 1 ]]; then
    printf 'UNIT: %s\n' "$*"
  else
    systemctl "$@"
  fi
}

# Only exact hostnames are supported; wildcard/catch-all server names are not UI domains.
valid_node_hostname() {
  local hostname="$1" label labels
  [[ ${#hostname} -le 253 && "$hostname" != *..* && "$hostname" != .* && "$hostname" != *. ]] || return 1
  IFS='.' read -ra labels <<< "$hostname"
  (( ${#labels[@]} )) || return 1
  for label in "${labels[@]}"; do
    [[ ${#label} -le 63 && "$label" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$ ]] || return 1
  done
}

ensure_node_account() {
  local account="$1" target="$2"
  getent group "$account" >/dev/null || groupadd --system "$account"
  id "$account" >/dev/null 2>&1 || useradd --system --gid "$account" --home-dir "$target" --shell /usr/sbin/nologin "$account"
}

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

sync_node_units() (
  local template_dir="$1" unit_dir="$2" role="$3" prefix="$4" mode="${5:-apply}" reload="${6:-1}"
  local temporary template filename unit_name destination rendered staged='' changed=0
  [[ "$role" == primary || "$role" == main || "$role" == worker ]] || return 2
  [[ "$mode" == apply || "$mode" == dry-run ]] || return 2
  [[ "$reload" == 0 || "$reload" == 1 ]] || return 2
  [[ "$unit_dir" == /* && "$unit_dir" != / && "$prefix" =~ ^[a-zA-Z0-9_-]+$ ]] || return 2
  temporary="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-units.XXXXXXXX")"
  trap 'rm -rf -- "$temporary"; [[ -z "$staged" ]] || rm -f -- "$staged"' EXIT
  shopt -s nullglob
  local -a templates=("$template_dir"/xcrawler-*.service "$template_dir"/xcrawler-*.timer)
  (( ${#templates[@]} > 0 )) || { printf 'No systemd templates found in %s\n' "$template_dir" >&2; return 1; }

  for template in "${templates[@]}"; do
    filename="${template##*/}"
    unit_name="${filename#xcrawler-}"
    if [[ "$unit_name" == scheduler.service && "$role" == worker ]]; then
      destination="$unit_dir/$prefix-$unit_name"
      if [[ -e "$destination" ]]; then
        [[ ! -L "$destination" && -f "$destination" ]] || { printf 'Refusing to remove non-regular unit: %s\n' "$destination" >&2; return 1; }
        grep -Fxq 'Description=XCrawler scheduler' "$destination" && grep -Fq 'artisan schedule:work' "$destination" || {
          printf 'Refusing to remove an unmanaged scheduler unit: %s\n' "$destination" >&2
          return 1
        }
        printf 'REMOVE: %s (scheduler is only installed on primary nodes)\n' "$destination"
        diff -u --label "$destination" --label /dev/null "$destination" /dev/null || true
        if [[ "$mode" == apply ]]; then
          node_control disable --now "$prefix-scheduler"
          rm -- "$destination"
        fi
        changed=1
      fi
      continue
    fi

    destination="$unit_dir/$prefix-$unit_name"
    [[ ! -L "$destination" && ( ! -e "$destination" || -f "$destination" ) ]] || {
      printf 'Refusing to replace non-regular unit: %s\n' "$destination" >&2
      return 1
    }
    rendered="$temporary/$unit_name"
    render_node_template "$template" "$rendered"
    if [[ -f "$destination" ]] && cmp -s -- "$rendered" "$destination"; then
      printf 'UNCHANGED: %s\n' "$destination"
      continue
    fi

    printf 'UPDATE: %s\n' "$destination"
    if [[ -f "$destination" ]]; then
      diff -u --label "$destination" --label "$template" "$destination" "$rendered" || true
    else
      diff -u --label /dev/null --label "$template" /dev/null "$rendered" || true
    fi
    if [[ "$mode" == apply ]]; then
      install -d "$unit_dir"
      staged="$(mktemp "$unit_dir/.$unit_name.XXXXXXXX")"
      install -m 644 -- "$rendered" "$staged"
      mv -f -- "$staged" "$destination"
      staged=''
    fi
    changed=1
  done

  if (( changed && reload )) && [[ "$mode" == apply ]]; then
    node_control daemon-reload
  fi
  if [[ "$mode" == dry-run ]]; then
    if (( changed )); then printf 'DRY RUN: systemd unit changes shown above; no files written or daemon-reload requested.\n'
    else printf 'DRY RUN: systemd units already match; no changes or daemon-reload needed.\n'; fi
  elif (( ! changed )); then
    printf 'Systemd units already match; daemon-reload skipped.\n'
  fi
)

configure_node_role() {
  local role="$1" target="$2" unit_dir="$3" unit dropin expected staged
  shift 3
  NODE_ROLE_CHANGED=0
  for unit in "$@"; do
    dropin="$unit_dir/$unit.service.d/worker-role.conf"
    if [[ "$role" == worker ]]; then
      [[ "$unit" != "${UNIT_PREFIX}-scheduler" || -f "$unit_dir/$unit.service" ]] || continue
      expected="$(printf '[Unit]\nConditionPathExists=%s/shared/primary-role' "$target")"
      if [[ ! -f "$dropin" || "$(cat "$dropin")" != "$expected" ]]; then
        install -d "$(dirname -- "$dropin")"
        staged="$(mktemp "$(dirname -- "$dropin")/.worker-role.XXXXXXXX")"
        if ! { printf '%s\n' "$expected" > "$staged" && chmod 644 -- "$staged" && mv -f -- "$staged" "$dropin"; }; then
          rm -f -- "$staged"
          return 1
        fi
        staged=''
        NODE_ROLE_CHANGED=1
        node_control disable --now "$unit"
      fi
    else
      if [[ -f "$dropin" ]]; then
        rm -- "$dropin"
        NODE_ROLE_CHANGED=1
      fi
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

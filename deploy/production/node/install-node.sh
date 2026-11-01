#!/usr/bin/env bash
# Invoked by the wizard on an approved Ubuntu node only. Never on the workstation.
set -Eeuo pipefail
set +x
[[ "$EUID" == 0 ]] || { echo 'Run prerequisite installation as root.' >&2; exit 1; }
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
manifest="$root/deploy/manifest.json"
command -v jq >/dev/null || { echo 'Bootstrap jq with the OS package manager first.' >&2; exit 1; }
node_value() { jq -er --arg key "$1" '.node[$key]' "$manifest"; }
: "${TARGET_DIR:?}" "${SERVICE_USER:?}" "${UNIT_PREFIX:?}" "${GATEKEEPER_ADDRESS:?}" "${UI_HOST:?}" "${CC_HOST:?}" "${PHP_BINARY:?}" "${FPM_UNIT:?}" "${EMBEDDER_BIND:?}" "${EMBEDDER_PORT:?}"
[[ "$TARGET_DIR" =~ ^/[a-zA-Z0-9_./-]+$ && "$TARGET_DIR" != / && "$SERVICE_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$UNIT_PREFIX" =~ ^[a-zA-Z0-9_-]+$ ]] || exit 1
# shellcheck source=/dev/null
source "$(node_value os_release)"
[[ "$ID" == ubuntu && "${VERSION_ID%%.*}" -ge "$(node_value minimum_ubuntu)" ]] || { echo 'Unsupported Ubuntu version.' >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive
apt-get update
mapfile -t packages < <(jq -r '.node.packages[]' "$manifest")
apt-get install -y "${packages[@]}"
add-apt-repository -y "$(node_value php_ppa)"
curl -fsSL "$(node_value node_key)" | gpg --dearmor --yes -o "$(node_value node_key_path)"
printf 'deb [signed-by=%s] %s nodistro main\n' "$(node_value node_key_path)" "$(node_value node_repo)" > "$(node_value node_list_path)"
install -d "$(dirname -- "$(node_value pg_key_path)")"
curl -fsSL "$(node_value pg_key)" -o "$(node_value pg_key_path)"
printf 'deb [signed-by=%s] %s %s-pgdg main\n' "$(node_value pg_key_path)" "$(node_value pg_repo)" "$VERSION_CODENAME" > "$(node_value pg_list_path)"
apt-get update
mapfile -t packages < <(jq -r '.node.runtime_packages[]' "$manifest")
apt-get install -y "${packages[@]}"
# shellcheck disable=SC2016 # PHP evaluates these expressions.
"$PHP_BINARY" -r 'foreach (["mongodb", "redis", "pdo_pgsql", "pcntl", "intl", "mbstring"] as $ext) { if (!extension_loaded($ext)) { exit(1); } }'
if [[ ! -x "$(node_value composer_dir)/composer" ]]; then
  installer="$(mktemp)"
  trap 'rm -f "$installer"' EXIT
  curl -fsSL "$(node_value composer_installer)" -o "$installer"
  expected="$(curl -fsSL "$(node_value composer_signature)")"
  [[ "$(sha384sum "$installer" | cut -d ' ' -f1)" == "$expected" ]]
  "$PHP_BINARY" "$installer" --install-dir="$(node_value composer_dir)" --filename=composer
  rm -f "$installer"
  trap - EXIT
fi
id "$SERVICE_USER" >/dev/null 2>&1 || useradd --system --home-dir "$TARGET_DIR" --shell /usr/sbin/nologin "$SERVICE_USER"
usermod -aG "$SERVICE_USER" www-data
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 2750 "$TARGET_DIR" "$TARGET_DIR/releases" "$TARGET_DIR/shared"
for folder in app/private app/public framework/cache/data framework/sessions framework/views logs; do
  install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 2770 "$TARGET_DIR/shared/storage/$folder"
done
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 2770 "$TARGET_DIR/shared/huggingface"
if [[ ! -x "$TARGET_DIR/shared/embedder-venv/bin/python" ]]; then
  runuser -u "$SERVICE_USER" -- python3 -m venv "$TARGET_DIR/shared/embedder-venv"
fi
runuser -u "$SERVICE_USER" -- "$TARGET_DIR/shared/embedder-venv/bin/pip" install -r "$root/embedder/requirements.production.txt"
# shellcheck source=deploy/lib/node-template.sh
source "$root/deploy/lib/node-template.sh"
if [[ "$NODE_ROLE" == primary ]]; then
render_node_template "$root/deploy/production/node/xcrawler-fpm.conf" "$(node_value fpm_pool_dir)/$UNIT_PREFIX.conf"
render_node_template "$root/deploy/production/node/xcrawler.nginx.conf" "$(node_value nginx_available)/$UNIT_PREFIX"
ln -sfn "$(node_value nginx_available)/$UNIT_PREFIX" "$(node_value nginx_enabled)/$UNIT_PREFIX"
fi
for service in horizon embedder scheduler; do
  render_node_template "$root/deploy/production/node/xcrawler-$service.service" "$(node_value unit_dir)/$UNIT_PREFIX-$service.service"
done
# A worker must never become a second scheduler or web node after a reboot.
blocked_units=("$NGINX_UNIT" "$UNIT_PREFIX-scheduler")
for unit in "${blocked_units[@]}"; do
  dropin="$(node_value unit_dir)/$unit.service.d/worker-role.conf"
  if [[ "$NODE_ROLE" == worker ]]; then
    install -d "$(dirname -- "$dropin")"
    printf '[Unit]\nConditionPathExists=%s/shared/primary-role\n' "$TARGET_DIR" > "$dropin"
    systemctl disable --now "$unit"
  else
    rm -f "$dropin"
  fi
done
systemctl enable "$(node_value exporter_unit)"
# Only these complete command vectors can be invoked by the service account.
sudoers="$(node_value sudoers_dir)/$UNIT_PREFIX-units"
# Restrict privilege to individual manifest-selected units; scheduler remains opt-in.
: > "$sudoers"
units=("$UNIT_PREFIX-horizon" "$UNIT_PREFIX-embedder")
if [[ "$NODE_ROLE" == primary ]]; then units+=("$FPM_UNIT" "$NGINX_UNIT" "$UNIT_PREFIX-scheduler"); fi
for unit in "${units[@]}"; do
  for action in stop start restart enable is-active; do
    printf '%s ALL=(root) NOPASSWD: /usr/bin/systemctl %s %s\n' "$SERVICE_USER" "$action" "$unit" >> "$sudoers"
  done
done
chmod 440 "$sudoers"
visudo -cf "$sudoers"
if [[ "$NODE_ROLE" == primary ]]; then nginx -t; fi
systemctl daemon-reload
# Enable/start is deferred to the wizard after release verification.
# Application units start only after the wizard creates and verifies a release.
echo 'Node prerequisites and rendered configuration verified.'

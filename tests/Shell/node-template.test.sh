#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
mkdir -p "$root/.tmp"
fixture="$(mktemp -d "$root/.tmp/node-template.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT
# shellcheck source=deploy/lib/node-template.sh
source "$root/deploy/lib/node-template.sh"
export TARGET_DIR="$fixture/custom" SERVICE_USER=custom UNIT_PREFIX=custom-unit
export GATEKEEPER_ADDRESS=203.0.113.1 UI_HOST=ui.example.test CC_HOST=cc.example.test
export PHP_BINARY=/operator/php EMBEDDER_BIND=127.0.0.2 EMBEDDER_PORT=9001
for file in "$root/deploy/production/node/"*.conf "$root/deploy/production/node/"*.service; do
  render_node_template "$file" "$fixture/${file##*/}"
  if grep -q '@[A-Z_]*@' "$fixture/${file##*/}"; then echo 'FAIL: unresolved template'; exit 1; fi
  grep -q "$TARGET_DIR\|$SERVICE_USER" "$fixture/${file##*/}"
done
grep -q 'server_name ui.example.test cc.example.test' "$fixture/xcrawler.nginx.conf"
grep -q 'ExecStart=/operator/php artisan horizon' "$fixture/xcrawler-horizon.service"
grep -q -- '--host 127.0.0.2 --port 9001' "$fixture/xcrawler-embedder.service"
for unit in "$fixture/xcrawler-horizon.service" "$fixture/xcrawler-embedder.service" "$fixture/xcrawler-scheduler.service"; do
  grep -Fxq 'StartLimitIntervalSec=0' "$unit"
  grep -Fxq 'StartLimitBurst=5' "$unit"
  grep -Fxq 'Restart=on-failure' "$unit"
  grep -Fxq 'RestartSec=15s' "$unit"
done
grep -Fxq 'ExecStartPre=/operator/php artisan health:startup --role=worker' "$fixture/xcrawler-horizon.service"
grep -Fxq 'ExecStartPre=/operator/php artisan health:startup --role=worker' "$fixture/xcrawler-embedder.service"
grep -Fxq 'ExecStartPre=/operator/php artisan health:startup --role=scheduler' "$fixture/xcrawler-scheduler.service"
if UI_HOST=$'invalid\nserver' render_node_template "$root/deploy/production/node/xcrawler.nginx.conf" "$fixture/bad"; then echo 'FAIL: multiline template injection accepted'; exit 1; fi
echo 'OK (9 node template scenarios)'

# Exercise actual role configuration with only systemctl replaced.
systemctl() { printf '%s\n' "$*" >> "$fixture/units"; }
mkdir -p "$fixture/systemd"
: > "$fixture/systemd/custom-scheduler.service"
configure_node_role worker "$TARGET_DIR" "$fixture/systemd" custom-nginx custom-scheduler
for unit in custom-nginx custom-scheduler; do
  grep -Fxq "ConditionPathExists=$TARGET_DIR/shared/primary-role" "$fixture/systemd/$unit.service.d/worker-role.conf"
  grep -Fxq "disable --now $unit" "$fixture/units"
done
[[ "$(wc -l < "$fixture/units")" == 2 ]]
printf 'preserve' > "$fixture/systemd/custom-nginx.service.d/operator.conf"
configure_node_role primary "$TARGET_DIR" "$fixture/systemd" custom-nginx custom-scheduler
[[ ! -e "$fixture/systemd/custom-nginx.service.d/worker-role.conf" ]]
[[ ! -e "$fixture/systemd/custom-scheduler.service.d/worker-role.conf" ]]
[[ "$(cat "$fixture/systemd/custom-nginx.service.d/operator.conf")" == preserve ]]
echo 'OK (8 worker role drop-in assertions)'

prior_calls="$(wc -l < "$fixture/units")"
WIZARD_NO_SYSTEMD=1 node_control daemon-reload > "$fixture/plan"
grep -Fxq 'UNIT: daemon-reload' "$fixture/plan"
[[ "$(wc -l < "$fixture/units")" == "$prior_calls" ]]
echo 'OK (2 no-systemd assertions)'

for bad in 'ui.example; access_log /tmp/exposed; #' '*.example.test' '_' 'bad..test' '-bad.test' 'bad-.test'; do
  if valid_node_hostname "$bad"; then echo 'FAIL: unsafe hostname accepted'; exit 1; fi
done
for good in localhost ui.example.test ui-name.example.test; do valid_node_hostname "$good"; done
echo 'OK (9 exact-hostname assertions)'
getent() { [[ "$GROUP_EXISTS" == 1 ]]; }
groupadd() { GROUP_EXISTS=1; printf '%s\n' "$*" >> "$fixture/groups"; }
id() { [[ "$USER_EXISTS" == 1 ]]; }
useradd() { USER_EXISTS=1; printf '%s\n' "$*" >> "$fixture/users"; }
GROUP_EXISTS=0 USER_EXISTS=1
ensure_node_account existing "$TARGET_DIR"
[[ "$GROUP_EXISTS" == 1 && ! -e "$fixture/users" ]]
ensure_node_account existing "$TARGET_DIR"
[[ "$(wc -l < "$fixture/groups")" == 1 ]]
GROUP_EXISTS=0 USER_EXISTS=0
ensure_node_account fresh "$TARGET_DIR"
grep -q -- '--gid fresh' "$fixture/users"
unset -f getent groupadd id useradd
echo 'OK (3 service-account idempotency assertions)'

cat > "$fixture/startup-php" <<'STUB'
#!/usr/bin/env bash
[[ "$*" == 'artisan health:startup --role=worker' ]] || exit 2
if [[ "${PRIMARY_DEPENDENCY:-up}" == down ]]; then
  echo 'Startup blocked: primary dependency is unavailable.'
  exit 1
fi
if [[ "${SECONDARY_DEPENDENCY:-up}" == down ]]; then
  echo 'WARN: secondary dependency is unavailable.'
fi
STUB
chmod +x "$fixture/startup-php"
run_horizon_prestart() {
  local prestart
  local -a command
  prestart="$(sed -n 's/^ExecStartPre=//p' "$fixture/xcrawler-horizon.service")"
  [[ -n "$prestart" ]] || { echo 'FAIL: horizon unit has no ExecStartPre'; return 1; }
  prestart="${prestart/\/operator\/php/$fixture\/startup-php}"
  read -r -a command <<< "$prestart"
  "${command[@]}"
}
if PRIMARY_DEPENDENCY=down SECONDARY_DEPENDENCY=up run_horizon_prestart > "$fixture/primary-down.log" 2>&1; then
  echo 'FAIL: ExecStartPre accepted a down primary dependency'
  exit 1
fi
grep -q 'Startup blocked' "$fixture/primary-down.log"
PRIMARY_DEPENDENCY=up SECONDARY_DEPENDENCY=down run_horizon_prestart > "$fixture/secondary-down.log"
grep -q 'WARN:' "$fixture/secondary-down.log"
echo 'OK (2 ExecStartPre primary/secondary scenarios)'

templates="$fixture/unit-templates"
installed="$fixture/application-systemd"
mkdir -p "$templates"
cp "$root"/deploy/production/node/xcrawler-*.service "$templates/"
cp "$root"/deploy/production/node/xcrawler-*.timer "$templates/"
: > "$fixture/units"
TMPDIR="$fixture" sync_node_units "$templates" "$installed" primary "$UNIT_PREFIX" apply
[[ -f "$installed/$UNIT_PREFIX-horizon.service" ]]
[[ -f "$installed/$UNIT_PREFIX-embedder.service" ]]
[[ -f "$installed/$UNIT_PREFIX-scheduler.service" ]]
[[ -f "$installed/$UNIT_PREFIX-horizon-reconcile.service" ]]
[[ -f "$installed/$UNIT_PREFIX-horizon-reconcile.timer" ]]
[[ "$(grep -Fc 'daemon-reload' "$fixture/units")" == 1 ]]
echo 'OK (initial primary unit refresh uses one daemon-reload)'

: > "$fixture/units"
TMPDIR="$fixture" sync_node_units "$templates" "$installed" primary "$UNIT_PREFIX" apply > "$fixture/unchanged.log"
[[ ! -s "$fixture/units" ]]
grep -q 'already match; daemon-reload skipped' "$fixture/unchanged.log"
echo 'OK (unchanged units skip daemon-reload)'

sed -i 's/Description=XCrawler horizon/Description=XCrawler horizon refreshed/' "$templates/xcrawler-horizon.service"
: > "$fixture/units"
TMPDIR="$fixture" sync_node_units "$templates" "$installed" primary "$UNIT_PREFIX" apply > "$fixture/changed.log"
grep -q '^UPDATE:' "$fixture/changed.log"
grep -Fxq 'Description=XCrawler horizon refreshed' "$installed/$UNIT_PREFIX-horizon.service"
[[ "$(grep -Fc 'daemon-reload' "$fixture/units")" == 1 ]]
echo 'OK (changed units are diffed, installed and reloaded once)'

sed -i 's/Description=XCrawler embedder/Description=XCrawler embedder preview/' "$templates/xcrawler-embedder.service"
: > "$fixture/units"
TMPDIR="$fixture" sync_node_units "$templates" "$installed" primary "$UNIT_PREFIX" dry-run > "$fixture/dry-run.log"
grep -q '^UPDATE:' "$fixture/dry-run.log"
grep -q 'DRY RUN:' "$fixture/dry-run.log"
! grep -q 'Description=XCrawler embedder preview' "$installed/$UNIT_PREFIX-embedder.service"
[[ ! -s "$fixture/units" ]]
echo 'OK (dry-run displays changes without writing or reloading)'

: > "$fixture/units"
TMPDIR="$fixture" sync_node_units "$templates" "$installed" worker "$UNIT_PREFIX" apply > "$fixture/worker.log"
[[ ! -e "$installed/$UNIT_PREFIX-scheduler.service" ]]
[[ -f "$installed/$UNIT_PREFIX-horizon.service" && -f "$installed/$UNIT_PREFIX-embedder.service" ]]
grep -q 'scheduler is only installed on primary nodes' "$fixture/worker.log"
grep -Fxq 'disable --now custom-unit-scheduler' "$fixture/units"
[[ "$(grep -Fc 'daemon-reload' "$fixture/units")" == 1 ]]
: > "$fixture/units"
TMPDIR="$fixture" sync_node_units "$templates" "$installed" worker "$UNIT_PREFIX" apply > "$fixture/worker-unchanged.log"
[[ ! -s "$fixture/units" ]]
echo 'OK (worker excludes scheduler and unchanged worker units do not reload)'

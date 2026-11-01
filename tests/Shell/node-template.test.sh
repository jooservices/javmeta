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
if UI_HOST=$'invalid\nserver' render_node_template "$root/deploy/production/node/xcrawler.nginx.conf" "$fixture/bad"; then echo 'FAIL: multiline template injection accepted'; exit 1; fi
echo 'OK (9 node template scenarios)'

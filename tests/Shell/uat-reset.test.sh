#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
export WORKSPACES_ROOT="$tmp/ws"
export CALLS="$tmp/calls"
export JAVMETA_APP_DIR="$WORKSPACES_ROOT/JOOservices/projects/javmeta-uat"
mkdir -p "$WORKSPACES_ROOT/scripts/docker" "$JAVMETA_APP_DIR/bootstrap/cache" "$tmp/bin"
export PATH="$tmp/bin:$PATH"
cat > "$WORKSPACES_ROOT/scripts/docker/runtime" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CALLS"
[[ "$PWD" == "$JAVMETA_APP_DIR" ]] || exit 2
if [[ "$*" == *ops:uat-targets* ]]; then
  [[ "${EFFECTIVE:-staging}" == uat && ! -e bootstrap/cache/config.php ]] || exit 1
  printf 'UAT_TARGETS_VERIFIED\n'
fi
STUB
cat > "$tmp/bin/systemctl" <<'STUB'
#!/usr/bin/env bash
printf 'systemctl %s\n' "$*" >> "$CALLS"
STUB
chmod +x "$WORKSPACES_ROOT/scripts/docker/runtime" "$tmp/bin/systemctl"
export JAVMETA_DEPLOY_POSTGRES_DATABASE=javmeta_uat
if bash "$root/deploy/uat-reset.sh" uat >"$tmp/refused" 2>&1; then echo 'FAIL: staging config accepted'; exit 1; fi
if grep -Eq 'systemctl|migrate:fresh|dataset:load' "$CALLS"; then echo 'FAIL: destructive command ran'; exit 1; fi
export EFFECTIVE=uat
: > "$CALLS"
: > "$JAVMETA_APP_DIR/bootstrap/cache/config.php"
if bash "$root/deploy/uat-reset.sh" uat >"$tmp/cache" 2>&1; then echo 'FAIL: mismatched cache accepted'; exit 1; fi
if grep -Eq 'systemctl|migrate:fresh|dataset:load' "$CALLS"; then echo 'FAIL: destructive command ran'; exit 1; fi
rm -- "$JAVMETA_APP_DIR/bootstrap/cache/config.php"
: > "$CALLS"
bash "$root/deploy/uat-reset.sh" uat --dry-run > "$tmp/dry"
grep -q 'migrate:fresh' "$tmp/dry"
grep -q 'dataset:load sample --index --force' "$tmp/dry"
if grep -Eq 'systemctl|migrate:fresh|dataset:load' "$CALLS"; then echo 'FAIL: destructive command ran'; exit 1; fi
mkdir -p "$WORKSPACES_ROOT/JOOservices/projects/javmeta"
if JAVMETA_APP_DIR="$WORKSPACES_ROOT/JOOservices/projects/javmeta" bash "$root/deploy/uat-reset.sh" uat --dry-run >"$tmp/main" 2>&1; then echo 'FAIL: main clone accepted'; exit 1; fi
echo 'PASS: effective staging targets, cached config and main clone refused before side effects; verified UAT dry run succeeds.'

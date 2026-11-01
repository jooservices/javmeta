#!/usr/bin/env bash
# All network, package and service commands are stubs; Git uses a local fixture.
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
mkdir -p "$root/.tmp"
fixture="$(mktemp -d "$root/.tmp/install-test.XXXXXX")"
trap 'result=$?; if (( result )); then cat "$fixture/log" >&2; fi; rm -rf -- "$fixture"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
script="$root/deploy/install.sh"
if bash "$script" --non-interactive > "$fixture/log" 2>&1; then fail 'missing answers accepted'; fi
grep -q 'REF' "$fixture/log" || fail 'missing REF diagnostic'
grep -q 'REPO_URL' "$fixture/log" || fail 'missing repository diagnostic'
mkdir -p "$fixture/bin" "$fixture/repo/storage" "$fixture/repo/ui-tests"
export CALLS="$fixture/calls"
cat > "$fixture/bin/stub" <<'STUB'
#!/usr/bin/env bash
printf '%s %s\n' "${0##*/}" "$*" >> "$CALLS"
if [[ "${0##*/}" == php && "$*" == *probe.php* ]]; then
  if [[ "${FAIL_STEP:-}" == validation ]]; then exit 1; fi
  if [[ "${FAIL_STEP:-}" == timezone ]]; then echo "Database timezone MUST be UTC"; exit 1; fi
  if [[ "${FAIL_STEP:-}" == retry && ! -e "$CALLS.retry" ]]; then touch "$CALLS.retry"; exit 1; fi
fi
if [[ "${0##*/}" == php && "$*" == *random_bytes* ]]; then echo generated-private-key; fi
if [[ "${0##*/}" == php && ( "$*" == *rawurlencode* || "$*" == *mongo-uri.php* ) ]]; then echo mongodb://fixture.invalid/fixture; fi
if [[ "${0##*/}" == php && "$*" == *operations:make-super-admin* ]]; then echo private-owner-password; fi
if [[ "${0##*/}" == id && "$*" == '-un' ]]; then echo fixture-user; exit 0; fi
if [[ "${0##*/}" == php && "$*" == *staging:readiness* ]]; then echo 'Staging is READY'; fi
if [[ "${0##*/}" == php && "$*" == *readiness.php* && "${FAIL_STEP:-}" == horizon ]]; then exit 1; fi
if [[ "${0##*/}" == php && "$*" == *readiness.php* && "$*" == *' 1' && "${FAIL_STEP:-}" == scheduler ]]; then exit 1; fi
if [[ "${0##*/}" == php && "$*" == *migrate* && "${FAIL_STEP:-}" == migrate ]]; then exit 1; fi
if [[ "${0##*/}" == php && "$*" == *staging:readiness* && "${FAIL_STEP:-}" == readiness ]]; then exit 1; fi
if [[ "${0##*/}" == sudo && "${FAIL_STEP:-}" == restart && "$*" == *restart* ]]; then exit 1; fi
if [[ "${0##*/}" == curl && "${FAIL_STEP:-}" == readiness ]]; then exit 1; fi
if [[ "${0##*/}" == node && "${FAIL_STEP:-}" == ui ]]; then exit 1; fi
if [[ "${0##*/}" == php && "${FAIL_STEP:-}" == import && "$*" == *settings:import-env* ]]; then exit 1; fi
if [[ "${0##*/}" == pg_restore ]]; then printf ';     dbname: fixture\n'; fi
STUB
chmod +x "$fixture/bin/stub"
for tool in php composer node sudo pg_restore psql curl id; do ln -s stub "$fixture/bin/$tool"; done
export PATH="$fixture/bin:$PATH"
git init -q -b candidate "$fixture/repo"
git -C "$fixture/repo" config user.name test
git -C "$fixture/repo" config user.email test@example.test
touch "$fixture/repo/artisan" "$fixture/repo/storage/.gitkeep" "$fixture/repo/ui-tests/render-smoke.mjs"
git -C "$fixture/repo" add .
git -C "$fixture/repo" -c core.hooksPath=/dev/null commit -qm 'test: Fixture'
git -C "$fixture/repo" tag v-test
sha="$(git -C "$fixture/repo" rev-parse HEAD)"
# Populate all required fields; optional integrations remain disabled.
jq --arg repo "$fixture/repo" --arg target "$fixture/node" --arg user "$(id -un 2>/dev/null || id -u)" '
  [.options[], .services[].fields[]] | map({key:.key,value:(if .secret then "private-test-value" elif .key == "CREDENTIALS_FILE" then ($target+".credentials") elif .default != "" then .default else "fixture" end)}) | from_entries
  | .REPO_URL=$repo | .TARGET_DIR=$target | .SERVICE_USER="fixture-user" | .DB_DATABASE="fixture" | .REF="candidate"
  | .APP_URL="https://fixture.invalid" | .DB_HOST="fixture.invalid" | .MONGO_URI="mongodb://fixture.invalid/fixture"
  | .APP_KEY="" | .CI_CHECKS="" | .INSTALL_NODE="0" | .READINESS_TIMEOUT="1" | .BACKUP_FILE=($target+".dump") | .BACKUP_RESTORE_VERIFIED="1"' "$root/deploy/manifest.json" > "$fixture/answers.json"
chmod 600 "$fixture/answers.json"
rm -f "$CALLS"
printf backup > "$fixture/node.dump"
for ref in candidate v-test "$sha"; do
  bash "$script" --non-interactive --config "$fixture/answers.json" --ref "$ref" > "$fixture/log" 2>&1
  grep -q 'DRY RUN' "$fixture/log" || fail 'missing dry run plan'
  [[ ! -e "$fixture/node" && ! -e "$CALLS" ]] || fail 'dry run changed state or contacted a service'
done
! grep -q 'private-test-value' "$fixture/log" || fail 'secret in output'
chmod 644 "$fixture/answers.json"
if bash "$script" --config "$fixture/answers.json" --non-interactive > "$fixture/log" 2>&1; then fail 'public config accepted'; fi
chmod 600 "$fixture/answers.json"
if FAIL_STEP=validation bash "$script" --config "$fixture/answers.json" --non-interactive --skip-os-install --apply > "$fixture/log" 2>&1; then fail 'invalid service accepted'; fi
[[ ! -e "$fixture/node" ]] || fail 'failed validation changed node'
if FAIL_STEP=timezone bash "$script" --config "$fixture/answers.json" --non-interactive --skip-os-install --apply > "$fixture/log" 2>&1; then fail 'non-UTC database accepted'; fi
grep -q 'timezone MUST be UTC' "$fixture/log" || fail 'timezone diagnostic missing'
jq '.INSTALL_NODE="1"' "$fixture/answers.json" > "$fixture/validate.json"
chmod 600 "$fixture/validate.json"
FAIL_STEP=retry bash "$script" --config "$fixture/validate.json" --validate-only --apply <<< $'y\n\n\n\n\n\n\n\n' > "$fixture/log" 2>&1
grep -q 'retry' "$fixture/log" || fail 'validation did not re-prompt'
! grep -q 'install-node.sh' "$CALLS" || fail 'validate-only ran native installer'
[[ ! -e "$fixture/node" ]] || fail 'validate-only changed node'
mkdir -p "$fixture/node/releases/old" "$fixture/node/shared"
ln -s "$fixture/node/releases/old" "$fixture/node/current"
for failure in ui migrate import restart readiness horizon; do
  if FAIL_STEP="$failure" bash "$script" --non-interactive --config "$fixture/answers.json" --skip-os-install --apply > "$fixture/log" 2>&1; then fail "ignored $failure"; fi
  [[ "$(readlink "$fixture/node/current")" == "$fixture/node/releases/old" ]] || fail 'rollback failed'
  ! grep -q 'private-test-value' "$fixture/log" || fail 'secret leaked on failure'
done
rm -- "$fixture/node/current"
bash "$script" --non-interactive --config "$fixture/answers.json" --skip-os-install --apply > "$fixture/log" 2>&1
[[ "$(readlink "$fixture/node/current")" != "$fixture/node/releases/old" ]] || fail 'release not activated'
[[ "$(stat -c '%a' "$fixture/node/shared/.env")" == 640 ]] || fail 'unsafe env mode'
[[ "$(stat -c '%a' "$fixture/node.credentials")" == 600 ]] || fail 'unsafe credentials mode'
grep -q 'OWNER_PASSWORD=private-owner-password' "$fixture/node.credentials" || fail 'owner password not captured'
owner_runs="$(grep -c 'operations:make-super-admin' "$CALLS" || true)"
grep -q 'artisan es:setup' "$CALLS" || fail 'missing ES setup'
grep -q 'artisan crawler:sync-sources' "$CALLS" || fail 'missing source sync'
grep -q "CRAWLERX_PLAYWRIGHT_SCRIPT=\"$fixture/node/current/vendor/" "$fixture/node/shared/.env" || fail 'Playwright path not under root'
# Re-runs preserve the generated key and existing configuration.
cp "$fixture/node/shared/.env" "$fixture/env-before"
cp "$fixture/node.credentials" "$fixture/credentials-before"
bash "$script" --non-interactive --config "$fixture/answers.json" --skip-os-install --apply > "$fixture/log" 2>&1
cmp "$fixture/env-before" "$fixture/node/shared/.env" || fail 'rerun changed env'
cmp "$fixture/credentials-before" "$fixture/node.credentials" || fail 'rerun changed credentials'
[[ "$(grep -c 'operations:make-super-admin' "$CALLS" || true)" == "$owner_runs" ]] || fail 'rerun rotated the owner password'
for ref in candidate v-test "$sha"; do
  bash "$script" --non-interactive --config "$fixture/answers.json" --ref "$ref" --skip-os-install --no-start --apply > "$fixture/log" 2>&1
  [[ "$(git -C "$fixture/node/current" rev-parse HEAD)" == "$sha" ]] || fail 'wrong selected commit'
done
! grep -q '^JAVMETA_SENTRY_DSN=' "$fixture/node/shared/.env" || fail 'disabled Sentry was enabled by an env value'
grep -q '^MONGO_AUTH_SOURCE="fixture"$' "$fixture/node/shared/.env" || fail 'Mongo auth source did not follow selected database'
if bash "$script" --non-interactive --config "$fixture/answers.json" --ci-check > "$fixture/log" 2>&1; then fail 'empty explicit CI gate accepted'; fi
grep -q 'requires CI_CHECKS' "$fixture/log" || fail 'missing CI gate diagnostic'
if bash "$script" --non-interactive --config "$fixture/answers.json" --no-systemd --apply > "$fixture/log" 2>&1; then fail 'simulated units counted as readiness'; fi
grep -q 'requires --no-start' "$fixture/log" || fail 'missing unit simulation diagnostic'
if FAIL_STEP=scheduler bash "$script" --non-interactive --config "$fixture/answers.json" --start-scheduler --apply > "$fixture/log" 2>&1; then fail 'unhealthy scheduler accepted'; fi
# Default credentials stay with the service-owned release target after sudo handoff.
jq --arg target "$fixture/default-node" 'del(.CREDENTIALS_FILE) | .TARGET_DIR=$target' "$fixture/answers.json" > "$fixture/default-answers.json"
chmod 600 "$fixture/default-answers.json"
bash "$script" --non-interactive --config "$fixture/default-answers.json" --skip-os-install --no-start --apply > "$fixture/log" 2>&1
[[ "$(stat -c '%a' "$fixture/default-node/shared/XCrawler-credentials.txt")" == 600 ]] || fail 'unsafe default credentials mode'
grep -q 'OWNER_PASSWORD=private-owner-password' "$fixture/default-node/shared/XCrawler-credentials.txt" || fail 'default credentials file was not written'
# Example entries are all declared; optional/empty values must be explicit too.
sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$root/deploy/production/env.production.example" | sort -u > "$fixture/example"
jq -r '.services[].fields[].key' "$root/deploy/manifest.json" | sort -u > "$fixture/manifest"
[[ -z "$(comm -23 "$fixture/example" "$fixture/manifest")" ]] || fail 'manifest misses example keys'
if grep -REn --include='*.sh' '^[[:space:]]*[^#[:space:]].*(develop|master|origin/)' "$root/deploy"; then fail 'branch literal in executable logic'; fi
# A worker must not migrate a database shared with the primary.
: > "$CALLS"
bash "$script" --non-interactive --config "$fixture/answers.json" --role worker --skip-os-install --no-systemd --no-start --apply > "$fixture/log" 2>&1
! grep -Eq 'artisan (migrate|es:setup|settings:import-env|crawler:sync-sources|operations:make-super-admin)' "$CALLS" || fail 'worker ran migrations'
grep -q 'no migrations' "$fixture/log" || fail 'worker migration skip not disclosed'
! grep -q 'private-test-value\|private-owner-password' "$fixture/log" || fail 'worker leaked credentials'
if bash "$script" --non-interactive --config "$fixture/answers.json" --role worker --target "$fixture/fresh-worker" > "$fixture/log" 2>&1; then fail 'new worker accepted without primary APP_KEY'; fi
grep -q 'copy the primary shared env/key' "$fixture/log" || fail 'missing worker key diagnostic'
echo 'OK (32 install wizard scenarios)'

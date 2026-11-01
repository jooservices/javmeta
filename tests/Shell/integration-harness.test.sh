#!/usr/bin/env bash
set -euo pipefail

root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
mkdir -p "$root/.tmp"
fixture="$(mktemp -d "$root/.tmp/integration-harness-test.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/bin"
export APP_KEY_CHECK_FILE="$fixture/app-key-check"

cat > "$fixture/bin/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$*" == *' run --rm gate vendor/bin/phpunit '* ]]; then
  suffix="${JAVMETA_CI_APP_KEY#base64:}"
  [[ "$suffix" =~ ^[A-Za-z0-9+/]{43}=$ ]] || exit 1
  printf 'valid\n' > "$APP_KEY_CHECK_FILE"
fi
STUB
chmod +x "$fixture/bin/docker"

if ! env -u JAVMETA_CI_APP_KEY PATH="$fixture/bin:$PATH" TMPDIR="$root/.tmp" JAVMETA_CI_SKIP_BUILD=1 \
  COMPOSE_PROJECT_NAME=javmeta-integration-harness-test bash "$root/docker/ci/integration" --filter NeverRun; then
  echo 'Integration harness failed with stubbed Docker.' >&2
  exit 1
fi

[[ "$(cat "$APP_KEY_CHECK_FILE")" == valid ]]
echo 'OK (integration harness provisions an ephemeral APP_KEY)'

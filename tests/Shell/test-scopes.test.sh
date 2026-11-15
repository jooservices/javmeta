#!/usr/bin/env bash
# Scope runner validates selectors before invoking PHPUnit and preserves safe PHPUnit options.
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
ROOT="$(CDPATH='' cd -- "${SCRIPT_DIR}/../.." && pwd)"
RUN="${ROOT}/docker/ci/run"
TMP_ROOT="${ROOT}/.tmp"
mkdir -p "$TMP_ROOT"
TMP_DIR="$(mktemp -d "${TMP_ROOT}/test-scopes.XXXXXX")"
trap 'rm -rf -- "$TMP_DIR"' EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "${TMP_DIR}/bin"
cat > "${TMP_DIR}/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\0' "$@" > "$CAPTURE"
DOCKER
chmod +x "${TMP_DIR}/bin/docker"

assert_command() {
  local description="$1"
  shift
  local -a expected=("$@")
  [[ -f "$CAPTURE" ]] || fail "$description did not invoke the runtime"

  local -a captured=()
  mapfile -d '' -t captured < "$CAPTURE"
  local image_index=-1
  local index
  for index in "${!captured[@]}"; do
    if [[ "${captured[index]}" == "$IMAGE" ]]; then
      image_index="$index"
    fi
  done
  ((image_index >= 0)) || fail "$description did not select the configured CI image"

  local -a actual=("${captured[@]:image_index+1}")
  [[ "${#actual[@]}" -eq "${#expected[@]}" ]] || fail "$description changed the argument count: ${actual[*]}"
  for index in "${!expected[@]}"; do
    [[ "${actual[index]}" == "${expected[index]}" ]] || fail "$description changed argument $index: ${actual[index]}"
  done
  rm -f -- "$CAPTURE"
}

assert_rejected() {
  local description="$1"
  shift
  if env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
    GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
    CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" "$RUN" test-scope "$@" >/dev/null 2>&1; then
    fail "$description should have failed"
  fi
  [[ ! -e "$CAPTURE" ]] || fail "$description reached the runtime"
}

IMAGE="javmeta-ci:test"
CAPTURE="${TMP_DIR}/docker-args"

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" test-scope unit --path tests/Unit/QueueConfigurationTest.php --group "custom group" --order-by defect --colors=never
assert_command 'Unit path selection' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  tests/Unit/QueueConfigurationTest.php --group "custom group" --order-by defect --colors=never

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" test-scope unit --path tests/Unit/QueueConfigurationTest.php --coverage-text
assert_command 'Optional coverage text flag' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  tests/Unit/QueueConfigurationTest.php --coverage-text

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" test-scope unit --path tests/Unit/QueueConfigurationTest.php \
  --coverage-clover build/scope-coverage.xml
assert_command 'Required coverage report value' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  tests/Unit/QueueConfigurationTest.php --coverage-clover build/scope-coverage.xml

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" test-scope feature --filter test_enrollment --testdox
assert_command 'Feature filter selection' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  --testsuite Feature --filter test_enrollment --testdox

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" test-scope unit --all --colors=never
assert_command 'Full Unit suite selection' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  --testsuite Unit --colors=never

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" test-scope feature --all
assert_command 'Full Feature suite selection' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  --testsuite Feature

# The public Composer runner must also preserve optional and required PHPUnit options.
env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" composer test:unit -- --path tests/Unit/QueueConfigurationTest.php --coverage-text
assert_command 'Public Composer optional coverage flag' \
  composer test:unit -- --path tests/Unit/QueueConfigurationTest.php --coverage-text

env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" composer test:unit -- --path tests/Unit/QueueConfigurationTest.php \
  --coverage-clover build/scope-coverage.xml
assert_command 'Public Composer required coverage value' \
  composer test:unit -- --path tests/Unit/QueueConfigurationTest.php \
  --coverage-clover build/scope-coverage.xml

assert_rejected 'Missing selector' unit
assert_rejected 'Unknown path' unit --path tests/Unit/does-not-exist.php
assert_rejected 'Cross-suite path' unit --path tests/Feature/Nodes/NodeEnrollmentEndpointsTest.php
assert_rejected 'Empty filter' unit --filter ''
assert_rejected 'Scope override' unit --filter test_queue --testsuite Feature
assert_rejected 'Abbreviated scope override' unit --path tests/Unit/QueueConfigurationTest.php --testsuit=Feature
assert_rejected 'Conflicting selectors' unit --all --path tests/Unit/QueueConfigurationTest.php

ln -s "$ROOT" "${TMP_DIR}/javmeta-link"
env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "${TMP_DIR}/javmeta-link/docker/ci/run" test-scope unit --path tests/Unit/QueueConfigurationTest.php
assert_command 'Symlinked checkout path selection' \
  vendor/bin/phpunit --configuration phpunit.xml --fail-on-empty-test-suite \
  tests/Unit/QueueConfigurationTest.php

# The existing generic command path remains a direct command in the same CI image.
env -u JAVMETA_CI_RUNNER_IN_CONTAINER \
  GITHUB_ACTIONS=true JAVMETA_CI_SKIP_BUILD=1 JAVMETA_CI_IMAGE="$IMAGE" \
  CAPTURE="$CAPTURE" PATH="${TMP_DIR}/bin:${PATH}" \
  "$RUN" php -v
assert_command 'Generic runtime command' php -v

echo 'test scope runner checks: PASS'

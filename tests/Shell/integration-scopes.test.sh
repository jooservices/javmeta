#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$root/.tmp"
fixture="$(mktemp -d "$root/.tmp/integration-scopes-test.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT

mkdir -p "$fixture/project/docker/ci" "$fixture/project/docker/dev"
cp "$root/docker/ci/integration-shared" "$fixture/project/docker/ci/integration-shared"
chmod +x "$fixture/project/docker/ci/integration-shared"

cat > "$fixture/project/docker/dev/shared-run" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$@" > "$SHARED_RUN_ARGS"
count_file="${SHARED_RUN_ARGS}.count"
count=0
if [[ -f "$count_file" ]]; then read -r count < "$count_file"; fi
count=$((count + 1))
printf '%s\n' "$count" > "$count_file"
printf '%s\n' "$@" > "${SHARED_RUN_ARGS}.${count}"
if [[ "${RUN_REAL_PHPUNIT:-}" == true ]]; then
  [[ "$1" == --mode && "$3" == --integration ]] || exit 2
  shift 3
  if [[ "${1:-}" == --require-redis ]]; then shift; fi
  [[ "${1:-}" == -- ]] || exit 2
  shift
  [[ "$1" == vendor/bin/phpunit ]] || exit 2
  shift
  cd "$PHPUNIT_FIXTURE"
  exec "$PHPUNIT_BIN" "$@"
fi
if [[ -n "${SMOKE_MISSING_TEST:-}" && " $* " == *"$SMOKE_MISSING_TEST"* ]]; then
  echo 'No tests executed!' >&2
  exit 1
fi
for argument in "$@"; do
  if [[ "$argument" == *MissingTest* ]]; then
    echo 'No tests executed!' >&2
    exit 1
  fi
done
STUB
chmod +x "$fixture/project/docker/dev/shared-run"

runner="$fixture/project/docker/ci/integration-shared"
args_file="$fixture/shared-run.args"

assert_args() {
  local call="$1"
  shift
  local -a expected=("$@")
  local -a actual=()
  mapfile -t actual < "${args_file}.${call}"
  [[ "${actual[*]}" == "${expected[*]}" ]] || {
    printf 'Unexpected shared-run arguments.\nExpected: %s\nActual:   %s\n' \
      "${expected[*]}" "${actual[*]}" >&2
    exit 1
  }
}

rm -f -- "$args_file".*
rm -f -- "${args_file}.count"
SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=wt:t08 "$runner" --filter \
  'Tests\Integration\RedisConnectionTest::test_it_round_trips_a_key_with_the_integration_prefix'
assert_args 1 --mode wt:t08 --integration --require-redis -- vendor/bin/phpunit --configuration phpunit.integration.xml \
  --fail-on-empty-test-suite --fail-on-skipped --filter \
  '^Tests\\Integration\\RedisConnectionTest::test_it_round_trips_a_key_with_the_integration_prefix( with .*)?$'
rm -f -- "$args_file".*
rm -f -- "${args_file}.count"
SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=wt:t08 "$runner" --smoke
[[ "$(cat "${args_file}.count")" == 3 ]] || { echo 'Smoke must invoke each named member independently.' >&2; exit 1; }
assert_args 1 --mode wt:t08 --integration -- vendor/bin/phpunit --configuration phpunit.integration.xml \
  --fail-on-empty-test-suite --fail-on-skipped --filter \
  '^Tests\\Integration\\MovieRepositoryPostgresTest::test_postgres_text_search_ranks_results_and_advances_with_a_rank_cursor( with .*)?$'
assert_args 2 --mode wt:t08 --integration -- vendor/bin/phpunit --configuration phpunit.integration.xml \
  --fail-on-empty-test-suite --fail-on-skipped --filter \
  '^Tests\\Integration\\SourceLoginCookieStoreTest::test_cookies_are_encrypted_at_rest_in_mongo( with .*)?$'
assert_args 3 --mode wt:t08 --integration -- vendor/bin/phpunit --configuration phpunit.integration.xml \
  --fail-on-empty-test-suite --fail-on-skipped --filter \
  '^Tests\\Integration\\Search\\SearchBehaviorTest::test_rest_and_mcp_keyword_search_find_movie_by_code_japanese_title_english_title_and_performer( with .*)?$'

rm -f -- "$args_file".*
rm -f -- "${args_file}.count"
status=0
if SHARED_RUN_ARGS="$args_file" SMOKE_MISSING_TEST='SourceLoginCookieStoreTest::test_cookies_are_encrypted_at_rest_in_mongo' \
  JAVMETA_MODE=wt:t08 "$runner" --smoke >"$fixture/partial-smoke-output" 2>&1; then
  echo 'A smoke member missing from the suite must fail the smoke command.' >&2
  exit 1
else
  status=$?
fi
[[ "$status" -eq 1 ]] || { printf 'Partially missing smoke exited %s, expected 1.\n' "$status" >&2; exit 1; }
[[ "$(cat "${args_file}.count")" == 2 ]] || { echo 'Smoke did not stop at its missing second member.' >&2; exit 1; }
grep -Fq 'No tests executed!' "$fixture/partial-smoke-output"
grep -Fq 'SourceLoginCookieStoreTest::test_cookies_are_encrypted_at_rest_in_mongo' "${args_file}.2"

rm -f -- "$args_file".*
rm -f -- "${args_file}.count"
SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=wt:t08 "$runner" --all
assert_args 1 --mode wt:t08 --integration --require-redis -- vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-empty-test-suite

rm -f -- "$args_file".*
rm -f -- "${args_file}.count"
SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=wt:t08 "$runner"
assert_args 1 --mode wt:t08 --integration --require-redis -- vendor/bin/phpunit --configuration phpunit.integration.xml --fail-on-empty-test-suite

expect_rejected() {
  rm -f -- "$args_file".*
  rm -f -- "${args_file}.count"
  local status=0
  if SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=wt:t08 "$runner" "$@" >"$fixture/stdout" 2>"$fixture/stderr"; then
    echo "Expected selector to fail: $*" >&2
    exit 1
  else
    status=$?
  fi
  [[ "$status" -eq 2 ]] || { printf 'Invalid selector exited %s, expected 2.\n' "$status" >&2; exit 1; }
  [[ ! -e "${args_file}.count" ]] || { echo 'Invalid selection reached shared-run.' >&2; exit 1; }
}

expect_rejected --unknown
if grep -Fq -- '--unknown' "$fixture/stderr"; then
  echo 'Unknown selector values must not be echoed to stderr.' >&2
  exit 1
fi
expect_rejected --filter
expect_rejected --filter wt:t08
expect_rejected --filter ''
expect_rejected --filter 'Tests\Integration\Invalid-Name::test_does_not_exist'
expect_rejected --all-extra
expect_rejected --all test

rm -f -- "$args_file".*
rm -f -- "${args_file}.count"
status=0
if SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=test "$runner" --all >"$fixture/stdout" 2>"$fixture/stderr"; then
  echo 'Shared test mode must not run from this worktree selector.' >&2
  exit 1
else
  status=$?
fi
[[ "$status" -eq 2 ]] || { printf 'Unsafe test mode exited %s, expected 2.\n' "$status" >&2; exit 1; }
[[ ! -e "${args_file}.count" ]] || { echo 'Unsafe test mode reached shared-run.' >&2; exit 1; }

status=0
if SHARED_RUN_ARGS="$args_file" JAVMETA_MODE=wt:t08 "$runner" --filter \
  'Tests\Integration\MissingTest::test_does_not_exist' >"$fixture/stdout" 2>"$fixture/stderr"; then
  echo 'A valid but unknown test must fail when PHPUnit reports zero tests.' >&2
  exit 1
else
  status=$?
fi
[[ "$status" -eq 1 ]] || { printf 'Zero-match selection exited %s, expected 1.\n' "$status" >&2; exit 1; }
grep -Fq 'No tests executed!' "$fixture/stderr"

mkdir -p "$fixture/project/tests/Integration/Search"
cat > "$fixture/project/phpunit.integration.xml" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="$root/vendor/autoload.php">
  <testsuites>
    <testsuite name="Integration">
      <file>tests/Integration/RedisConnectionTest.php</file>
      <file>tests/Integration/MovieRepositoryPostgresTest.php</file>
      <file>tests/Integration/SourceLoginCookieStoreTest.php</file>
      <file>tests/Integration/Search/SearchBehaviorTest.php</file>
    </testsuite>
  </testsuites>
</phpunit>
EOF
cat > "$fixture/project/tests/Integration/RedisConnectionTest.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class RedisConnectionTest extends TestCase
{
    public function test_it_round_trips_a_key_with_the_integration_prefix(): void
    {
        self::markTestSkipped('The shared harness cannot execute this test.');
    }
}
PHP
cat > "$fixture/project/tests/Integration/MovieRepositoryPostgresTest.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class MovieRepositoryPostgresTest extends TestCase
{
    public function test_postgres_text_search_ranks_results_and_advances_with_a_rank_cursor(): void
    {
        self::markTestSkipped('The shared harness cannot execute this test.');
    }
}
PHP
cat > "$fixture/project/tests/Integration/SourceLoginCookieStoreTest.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SourceLoginCookieStoreTest extends TestCase
{
    public function test_cookies_are_encrypted_at_rest_in_mongo(): void
    {
        self::markTestSkipped('The shared harness cannot execute this test.');
    }
}
PHP
cat > "$fixture/project/tests/Integration/Search/SearchBehaviorTest.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use PHPUnit\Framework\TestCase;

final class SearchBehaviorTest extends TestCase
{
    public function test_rest_and_mcp_keyword_search_find_movie_by_code_japanese_title_english_title_and_performer(): void
    {
        self::markTestSkipped('The shared harness cannot execute this test.');
    }
}
PHP

status=0
if RUN_REAL_PHPUNIT=true \
  PHPUNIT_BIN="$root/vendor/bin/phpunit" \
  PHPUNIT_FIXTURE="$fixture/project" \
  SHARED_RUN_ARGS="$args_file" \
  JAVMETA_MODE=wt:t08 \
  "$runner" --filter 'Tests\Integration\RedisConnectionTest::test_it_round_trips_a_key_with_the_integration_prefix' \
  >"$fixture/skipped-output" 2>&1; then
  echo 'A selected test skipped by PHPUnit must fail in shared mode.' >&2
  exit 1
else
  status=$?
fi
[[ "$status" -eq 1 ]] || { printf 'Skipped test exited %s, expected 1.\n' "$status" >&2; cat "$fixture/skipped-output" >&2; exit 1; }
grep -Fq 'Skipped: 1' "$fixture/skipped-output"

status=0
if RUN_REAL_PHPUNIT=true \
  PHPUNIT_BIN="$root/vendor/bin/phpunit" \
  PHPUNIT_FIXTURE="$fixture/project" \
  SHARED_RUN_ARGS="$args_file" \
  JAVMETA_MODE=wt:t08 \
  "$runner" --smoke >"$fixture/smoke-skipped-output" 2>&1; then
  echo 'A smoke test skipped by PHPUnit must fail in shared mode.' >&2
  exit 1
else
  status=$?
fi
[[ "$status" -eq 1 ]] || { printf 'Skipped smoke test exited %s, expected 1.\n' "$status" >&2; cat "$fixture/smoke-skipped-output" >&2; exit 1; }
grep -Fq 'Skipped: 1' "$fixture/smoke-skipped-output"

echo 'OK (exact integration selector, skipped filter and smoke tests, smoke subset, full suite, and safe worktree mode)'

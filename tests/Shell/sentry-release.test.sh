#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
REPOSITORY_SCRIPT="${SCRIPT_DIR}/../../deploy/repository.sh"
TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-sentry-release.XXXXXX")"
trap 'rm -rf "${TEST_TMP}"' EXIT INT TERM

# shellcheck source=deploy/repository.sh
source "${REPOSITORY_SCRIPT}"

# A checkout gives the git short SHA.
git init -q "${TEST_TMP}/app"
git -C "${TEST_TMP}/app" -c user.name=test -c user.email=test@example.test commit -q --allow-empty -m "chore: Test"
JAVMETA_APP_DIR="${TEST_TMP}/app"
unset SENTRY_RELEASE
export_sentry_release
expected="$(git -C "${JAVMETA_APP_DIR}" rev-parse --short --verify HEAD)"
[[ "${SENTRY_RELEASE}" == "${expected}" ]] || { echo "SENTRY_RELEASE is ${SENTRY_RELEASE}, expected ${expected}" >&2; exit 1; }

# A directory without git must stop the deployment instead of baking "unknown".
mkdir "${TEST_TMP}/plain"
JAVMETA_APP_DIR="${TEST_TMP}/plain"
if (export_sentry_release) 2>/dev/null; then
  echo 'export_sentry_release accepted a directory without git' >&2
  exit 1
fi

echo 'sentry release test passed'

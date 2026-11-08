#!/usr/bin/env bash
# Ensure crawlerx Node deps + Chromium exist (volume-mounted package).
set -euo pipefail

CRAWLERX_ROOT="${CRAWLERX_ROOT:-/var/www/html/vendor/jooservices/crawlerx}"
BROWSER_PATH="${PLAYWRIGHT_BROWSERS_PATH:-/ms-playwright}"

if [[ ! -d "${CRAWLERX_ROOT}" ]]; then
  echo "crawlerx root missing: ${CRAWLERX_ROOT}" >&2
  exit 1
fi

if [[ ! -d "${CRAWLERX_ROOT}/node_modules/playwright" ]]; then
  echo "Installing crawlerx npm dependencies..."
  (cd "${CRAWLERX_ROOT}" && npm ci)
fi

if ! compgen -G "${BROWSER_PATH}/chromium-*" > /dev/null; then
  echo "Installing Playwright Chromium into ${BROWSER_PATH}..."
  mkdir -p "${BROWSER_PATH}"
  (cd "${CRAWLERX_ROOT}" && PLAYWRIGHT_BROWSERS_PATH="${BROWSER_PATH}" npx playwright install --with-deps chromium)
fi

exec "$@"

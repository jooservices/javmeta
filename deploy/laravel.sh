#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

: "${JAVMETA_APP_DIR:=/home/joos/javmeta}"
: "${JAVMETA_REPO_URL:=https://github.com/jooservices/javmeta.git}"
: "${JAVMETA_BRANCH:=develop}"
: "${JAVMETA_COMPOSE_FILE:=docker-compose.yml}"
: "${JAVMETA_PHP_SERVICE:=api}"

# shellcheck disable=SC1091
source "${SCRIPT_DIR}/repository.sh"

trap 'printf "deploy: failed at line %s: %s\n" "$LINENO" "$BASH_COMMAND" >&2' ERR

require_command git
require_command docker

sync_repository
prepare_environment

if ! grep -Eq '^CRAWLERX_FLARESOLVERR_REQUIRED=(false|0|no)$' "${JAVMETA_APP_DIR}/.env"; then
  die 'control deployment requires CRAWLERX_FLARESOLVERR_REQUIRED=false in .env'
fi

export_sentry_release
compose config --quiet
compose build "${JAVMETA_PHP_SERVICE}"
compose run --rm --no-deps --entrypoint composer "${JAVMETA_PHP_SERVICE}" \
  install --no-dev --no-interaction --prefer-dist --optimize-autoloader
compose run --rm --no-deps --entrypoint php "${JAVMETA_PHP_SERVICE}" artisan migrate --force
compose run --rm --no-deps --entrypoint php "${JAVMETA_PHP_SERVICE}" artisan optimize:clear
compose up -d --force-recreate api mcp

printf 'deploy: Laravel control services prepared; scheduler remains stopped for verification\n'

#!/usr/bin/env bash

set -Eeuo pipefail

die() {
  printf 'deploy: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "required command is missing: $1"
}

sync_repository() {
  if [[ -d "${JAVMETA_APP_DIR}/.git" ]]; then
    if [[ -n "$(git -C "${JAVMETA_APP_DIR}" status --porcelain --untracked-files=all)" ]]; then
      die "repository is not clean: ${JAVMETA_APP_DIR}"
    fi

    local current_branch
    current_branch="$(git -C "${JAVMETA_APP_DIR}" branch --show-current)"
    [[ "${current_branch}" == "${JAVMETA_BRANCH}" ]] || die "expected branch ${JAVMETA_BRANCH}, found ${current_branch:-detached}"

    GIT_TERMINAL_PROMPT=0 git -C "${JAVMETA_APP_DIR}" pull --ff-only origin "${JAVMETA_BRANCH}"
    return
  fi

  [[ ! -e "${JAVMETA_APP_DIR}" ]] || die "application path exists but is not a Git repository: ${JAVMETA_APP_DIR}"

  mkdir -p "$(dirname "${JAVMETA_APP_DIR}")"
  GIT_TERMINAL_PROMPT=0 git clone --branch "${JAVMETA_BRANCH}" --single-branch "${JAVMETA_REPO_URL}" "${JAVMETA_APP_DIR}"
}

prepare_environment() {
  if [[ -n "${JAVMETA_ENV_SOURCE:-}" ]]; then
    [[ -f "${JAVMETA_ENV_SOURCE}" ]] || die "runtime env source does not exist: ${JAVMETA_ENV_SOURCE}"

    if [[ "${JAVMETA_ENV_SOURCE}" != "${JAVMETA_APP_DIR}/.env" ]]; then
      install -m 600 "${JAVMETA_ENV_SOURCE}" "${JAVMETA_APP_DIR}/.env"
    fi
  fi

  [[ -f "${JAVMETA_APP_DIR}/.env" ]] || die "missing ${JAVMETA_APP_DIR}/.env; set JAVMETA_ENV_SOURCE"
  chmod 600 "${JAVMETA_APP_DIR}/.env"
}

compose() {
  docker compose \
    --project-directory "${JAVMETA_APP_DIR}" \
    --env-file "${JAVMETA_APP_DIR}/.env" \
    --file "${JAVMETA_APP_DIR}/${JAVMETA_COMPOSE_FILE}" \
    --profile app \
    --profile control \
    --profile embed \
    "$@"
}

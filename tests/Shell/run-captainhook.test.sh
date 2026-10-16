#!/usr/bin/env bash
# tools/run-captainhook + docker/ci/run: CaptainHook runs in the CI image and a
# git worktree gets its common git dir mounted. Bash 3.2 compatible.
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="${SCRIPT_DIR}/../.."
TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-run-captainhook.XXXXXX")"
TEST_TMP="$(cd "${TEST_TMP}" && pwd -P)"
trap 'rm -rf "${TEST_TMP}"' EXIT INT TERM

FAKE_BIN="${TEST_TMP}/bin"
DOCKER_LOG="${TEST_TMP}/docker.log"
MAIN="${TEST_TMP}/main"
WORKTREE="${TEST_TMP}/wt"
export DOCKER_LOG

fail() {
  echo "FAIL: $*" >&2
  exit 1
}

mkdir -p "${FAKE_BIN}"
cat > "${FAKE_BIN}/docker" <<'FAKE_DOCKER'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "${DOCKER_LOG}"
FAKE_DOCKER
chmod +x "${FAKE_BIN}/docker"
PATH="${FAKE_BIN}:${PATH}"

git_quiet() {
  git -c user.name=Test -c user.email=test@example.invalid -c init.defaultBranch=develop "$@" >/dev/null 2>&1
}

mkdir -p "${MAIN}/tools" "${MAIN}/docker/ci"
cp "${REPO_ROOT}/tools/run-captainhook" "${MAIN}/tools/run-captainhook"
cp "${REPO_ROOT}/docker/ci/run" "${MAIN}/docker/ci/run"
printf 'vendor/\n' > "${MAIN}/.gitignore"
git_quiet -C "${MAIN}" init
git_quiet -C "${MAIN}" add -A
git_quiet -C "${MAIN}" commit -m 'Initial'
git_quiet -C "${MAIN}" worktree add -b wt "${WORKTREE}"

install_fake_captainhook() {
  mkdir -p "$1/vendor/bin"
  printf '#!/usr/bin/env bash\necho "host-captainhook $*"\n' > "$1/vendor/bin/captainhook"
  chmod +x "$1/vendor/bin/captainhook"
}

run_line() {
  grep '^run ' "${DOCKER_LOG}" | tail -n 1
}

# Missing vendor: gating hooks fail, non-gating post-* hooks are skipped.
: > "${DOCKER_LOG}"
if "${WORKTREE}/tools/run-captainhook" hook:commit-msg msg 2>/dev/null; then
  fail "commit-msg must fail without vendor/"
fi
"${WORKTREE}/tools/run-captainhook" hook:post-checkout a b 1 2>/dev/null || fail "post-checkout must be skipped without vendor/"
[[ ! -s "${DOCKER_LOG}" ]] || fail "docker must not run without vendor/"

install_fake_captainhook "${MAIN}"
install_fake_captainhook "${WORKTREE}"
COMMON_DIR="${MAIN}/.git"

# Main checkout: CI image, repo at /app, no extra git mount.
: > "${DOCKER_LOG}"
"${MAIN}/tools/run-captainhook" hook:commit-msg .git/COMMIT_EDITMSG
line="$(run_line)"
case "${line}" in
  *"--volume ${MAIN}:/app "*"javmeta-ci:local vendor/bin/captainhook hook:commit-msg .git/COMMIT_EDITMSG") ;;
  *) fail "main checkout docker run: ${line}" ;;
esac
case "${line}" in
  *"${COMMON_DIR}:${COMMON_DIR}"*) fail "main checkout must not mount the git dir twice: ${line}" ;;
esac

# Worktree: common git dir mounted at the same absolute path.
: > "${DOCKER_LOG}"
"${WORKTREE}/tools/run-captainhook" hook:commit-msg "${COMMON_DIR}/worktrees/wt/COMMIT_EDITMSG"
line="$(run_line)"
case "${line}" in
  *"--volume ${WORKTREE}:/app --volume ${COMMON_DIR}:${COMMON_DIR}:rw "*) ;;
  *) fail "worktree docker run: ${line}" ;;
esac

# Host opt-in: no docker at all.
: > "${DOCKER_LOG}"
output="$(JAVMETA_HOOKS_ON_HOST=1 "${WORKTREE}/tools/run-captainhook" hook:pre-commit)"
[[ "${output}" == "host-captainhook hook:pre-commit" ]] || fail "host opt-in output: ${output}"
[[ ! -s "${DOCKER_LOG}" ]] || fail "docker must not run with JAVMETA_HOOKS_ON_HOST=1"

echo "run-captainhook tests passed"

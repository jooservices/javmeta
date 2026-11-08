#!/usr/bin/env bash
# Joins this VM to an XCrawler installation as a worker node (docs/decisions/node-enrollment.md).
# The Command Center serves this script with its own address filled in:
#   curl -fsSL https://<command-center>/node/join.sh | sudo bash -s -- --token jne_... --name <node-name>
# It redeems the one-time token, receives the install answers over HTTPS, then runs the regular installer
# (deploy/install.sh) non-interactively, so there is a single install path. Nothing secret is printed or logged.
set -Eeuo pipefail

server='@SERVER_URL@'
token=''
name=''
install_args=()
plan_only=0

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
log() { printf '%s\n' "$*"; }
usage() {
  cat <<'USAGE'
Usage: join-node.sh --token jne_<64 hex> --name <node-name> [--server https://host] [--plan] [-- installer-args...]
  --token   one-time token shown by the Command Center (valid for a short time, usable once)
  --name    node name, lowercase letters, digits and hyphens, starting with a letter
  --server  override the Command Center address (default: the address this script was served from)
  --plan    only check this machine (Ubuntu 24.04, root, tools, address); do not redeem the token
  --        everything after is passed to deploy/install.sh (for example --no-systemd --no-start)
USAGE
}

while (($#)); do
  case "$1" in
    --token) token="${2:-}"; shift 2 ;;
    --name) name="${2:-}"; shift 2 ;;
    --server) server="${2:-}"; shift 2 ;;
    --plan) plan_only=1; shift ;;
    --help|-h) usage; exit 0 ;;
    --) shift; install_args=("$@"); break ;;
    *) usage >&2; die "Unknown argument: $1" ;;
  esac
done

[[ "$token" =~ ^jne_[a-f0-9]{64}$ ]] || die 'Missing or malformed --token (copy the whole command from the Command Center).'
[[ "$name" =~ ^[a-z][a-z0-9-]{2,63}$ ]] || die 'Missing or invalid --name (lowercase letters, digits and hyphens, 3-64 characters, starting with a letter).'
[[ -n "$server" && "$server" != '@SERVER_URL@' ]] || die 'No Command Center address: pass --server https://<command-center>.'
if [[ "$server" != https://* && "${JAVMETA_JOIN_ALLOW_HTTP:-0}" != 1 ]]; then
  die 'Refusing a plain HTTP address: the install answers contain credentials. Use https://.'
fi
server="${server%/}"

if (( ${JAVMETA_JOIN_EUID:-$EUID} != 0 )); then die 'Run as root (put sudo in front of bash).'; fi
os_release="${JAVMETA_JOIN_OS_RELEASE:-/etc/os-release}"
[[ -r "$os_release" ]] || die 'Cannot read the operating system release.'
# shellcheck disable=SC1090
version_id="$(. "$os_release" && printf '%s %s' "${ID:-}" "${VERSION_ID:-0}")"
[[ "${version_id%% *}" == ubuntu && "${version_id##* }" =~ ^(2[4-9]|[3-9][0-9])\. ]] || die "This needs Ubuntu 24.04 or newer (found: ${version_id})."
command -v curl >/dev/null || die 'curl is required.'

if (( plan_only )); then
  log "PLAN OK: $name can join $server (Ubuntu ${version_id##* }, root, curl present). The token was not used."
  exit 0
fi

export DEBIAN_FRONTEND=noninteractive
for tool in jq git; do
  command -v "$tool" >/dev/null || { apt-get update -qq && apt-get install -y -qq "$tool" ca-certificates >/dev/null; }
done

work="$(mktemp -d /root/javmeta-join.XXXXXX)"
chmod 700 "$work"
cleanup() { rm -rf -- "$work"; }
trap cleanup EXIT

log "Asking $server to enroll $name ..."
payload="$(jq -n --arg token "$token" --arg node_name "$name" '{token: $token, node_name: $node_name}')"
status="$(curl -sS --max-time 30 -o "$work/response.json" -w '%{http_code}' -H 'Content-Type: application/json' -H 'Accept: application/json' -d "$payload" "$server/node/enroll")" \
  || die 'Could not reach the Command Center.'
case "$status" in
  200) ;;
  404) die 'The token is not valid for this node: it may be wrong, already used or expired. Create a new one in the Command Center.' ;;
  422) die 'The Command Center rejected the token or the node name format.' ;;
  429) die 'Too many attempts; wait a minute and try again.' ;;
  *) die "Unexpected answer from the Command Center (HTTP $status)." ;;
esac

# Node-specific answers the Command Center does not know. The bundle wins where both define a key.
jq --arg name "$name" '.data.answers as $a | {
  TARGET_DIR: "/var/www/xcrawler", SERVICE_USER: "xcrawler", UNIT_PREFIX: "xcrawler", INSTALL_NODE: "1",
  OWNER_EMAIL: "root@localhost", JAVMETA_NODE_NAME: $name
} + $a' "$work/response.json" > "$work/answers.json"
chmod 600 "$work/answers.json"
shred -u "$work/response.json" 2>/dev/null || rm -f "$work/response.json"

repo_url="$(jq -r '.REPO_URL // empty' "$work/answers.json")"
ref="$(jq -r '.REF // empty' "$work/answers.json")"
[[ -n "$repo_url" && -n "$ref" ]] || die 'The Command Center has no release repository or ref set (Settings > Thêm node).'

log 'Fetching the installer ...'
git clone --quiet "$repo_url" "$work/src"
git -C "$work/src" checkout --quiet "$ref"

log 'Installing the worker (this takes several minutes) ...'
bash "$work/src/deploy/install.sh" --config "$work/answers.json" --non-interactive --role worker --apply "${install_args[@]}"
log "Done: $name joined $server."

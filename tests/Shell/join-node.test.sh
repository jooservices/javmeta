#!/usr/bin/env bash
# deploy/join-node.sh refuses everything unsafe before it touches the network or the machine.
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
script="$root/deploy/join-node.sh"
fixture="$(mktemp -d)"
trap 'rm -rf -- "$fixture"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
token="jne_$(printf 'a%.0s' $(seq 1 64))"
printf 'ID=ubuntu\nVERSION_ID="24.04"\n' > "$fixture/ubuntu"
printf 'ID=debian\nVERSION_ID="12"\n' > "$fixture/debian"
printf 'ID=ubuntu\nVERSION_ID="22.04"\n' > "$fixture/old"

run() { JAVMETA_JOIN_EUID=0 JAVMETA_JOIN_OS_RELEASE="$fixture/ubuntu" bash "$script" "$@" 2>&1 || true; }

out="$(run --name worker-1 --server https://cc.example.test)"
grep -q 'malformed --token' <<< "$out" || fail 'a missing token must be refused'
out="$(run --token jne_short --name worker-1 --server https://cc.example.test)"
grep -q 'malformed --token' <<< "$out" || fail 'a malformed token must be refused'
out="$(run --token "$token" --server https://cc.example.test)"
grep -q 'invalid --name' <<< "$out" || fail 'a missing name must be refused'
out="$(run --token "$token" --name 'Bad Name' --server https://cc.example.test)"
grep -q 'invalid --name' <<< "$out" || fail 'a bad name must be refused'
out="$(run --token "$token" --name worker-1)"
grep -q 'No Command Center address' <<< "$out" || fail 'a script without a server address must be refused'
out="$(run --token "$token" --name worker-1 --server http://cc.example.test)"
grep -q 'plain HTTP' <<< "$out" || fail 'plain HTTP must be refused'
out="$(JAVMETA_JOIN_EUID=1000 JAVMETA_JOIN_OS_RELEASE="$fixture/ubuntu" bash "$script" --token "$token" --name worker-1 --server https://cc.example.test 2>&1 || true)"
grep -q 'Run as root' <<< "$out" || fail 'a non-root run must be refused'
out="$(JAVMETA_JOIN_EUID=0 JAVMETA_JOIN_OS_RELEASE="$fixture/debian" bash "$script" --token "$token" --name worker-1 --server https://cc.example.test 2>&1 || true)"
grep -q 'Ubuntu 24.04 or newer' <<< "$out" || fail 'a non-Ubuntu host must be refused'
out="$(JAVMETA_JOIN_EUID=0 JAVMETA_JOIN_OS_RELEASE="$fixture/old" bash "$script" --token "$token" --name worker-1 --server https://cc.example.test 2>&1 || true)"
grep -q 'Ubuntu 24.04 or newer' <<< "$out" || fail 'Ubuntu 22.04 must be refused'

out="$(run --token "$token" --name worker-1 --server https://cc.example.test --plan)"
grep -q 'PLAN OK' <<< "$out" || fail '--plan must succeed on a supported machine'
grep -q 'The token was not used' <<< "$out" || fail '--plan must not redeem the token'
out="$(JAVMETA_JOIN_ALLOW_HTTP=1 run --token "$token" --name worker-1 --server http://localhost:8099 --plan)"
grep -q 'PLAN OK' <<< "$out" || fail 'plain HTTP is allowed only with the explicit rehearsal switch'
out="$(bash "$script" --help)"
grep -q 'one-time token' <<< "$out" || fail '--help must explain the token'
if grep -q "$token" <<< "$out"; then fail 'the token must never be echoed'; fi
echo 'join-node.sh refusals and plan verified'

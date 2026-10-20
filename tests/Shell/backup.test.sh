#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH='' cd -- "${SCRIPT_DIR}/../.." && pwd)"
BACKUP_SCRIPT="${REPO_ROOT}/deploy/backup/backup.sh"
RESTORE_SCRIPT="${REPO_ROOT}/deploy/backup/restore.sh"
TEST_TMP="$(mktemp -d "${TMPDIR:-/tmp}/javmeta-backup-test.XXXXXX")"
export TEST_TMP

cleanup() {
  rm -rf -- "$TEST_TMP"
}
trap cleanup EXIT INT TERM

fail_test() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

assert_exists() {
  [[ -e "$1" ]] || fail_test "Expected path to exist: $1"
}

assert_missing() {
  [[ ! -e "$1" ]] || fail_test "Expected path to be removed: $1"
}

export BACKUP_ROOT="${TEST_TMP}/dry-run-backups"
export BACKUP_NAMESPACE='javmeta_it'
export PGHOST='127.0.0.1'
export PGPORT='15435'
export PGUSER='javmeta_it'
export PGPASSWORD='test-password-do-not-print'
export PGDATABASE='javmeta_it'
export MONGO_URI='mongodb://javmeta_it:test-password-do-not-print@127.0.0.1:27022/javmeta_it?authSource=javmeta_it'
export ELASTICSEARCH_HOST='http://javmeta_it:test-password-do-not-print@127.0.0.1:19200'
export ELASTICSEARCH_SNAPSHOT_PATH='/tmp/javmeta-it-snapshots'
export AGE_RECIPIENT='age1testrecipient'
export AGE_IDENTITY="${TEST_TMP}/identity.txt"
export JAVMETA_SHARED_DOCKER='1'
export BACKUP_RETENTION_DAILY='7'
export BACKUP_RETENTION_WEEKLY='4'

dry_output="$("$BACKUP_SCRIPT" --namespace=javmeta_it --dry-run)"
[[ "$dry_output" == DRY\ RUN:* ]] || fail_test 'Backup dry run did not report its plan.'
[[ "$dry_output" != *'test-password-do-not-print'* ]] || fail_test 'Backup dry run printed a credential.'
assert_missing "$BACKUP_ROOT"

partial_dry_output="$(env -u ELASTICSEARCH_HOST -u ELASTICSEARCH_SNAPSHOT_PATH "$BACKUP_SCRIPT" --namespace=javmeta_it --postgres-mongo-only --dry-run)"
[[ "$partial_dry_output" == *'elasticsearch=not-included'* ]] || fail_test 'PostgreSQL and MongoDB-only dry run did not report Elasticsearch as excluded.'

if env -u AGE_RECIPIENT "$BACKUP_SCRIPT" --namespace=javmeta_it --dry-run >"${TEST_TMP}/missing-key.log" 2>&1; then
  fail_test 'Backup accepted a missing age recipient.'
fi
grep -q 'AGE_RECIPIENT' "${TEST_TMP}/missing-key.log" || fail_test 'Missing age recipient error was not clear.'
if grep -q 'test-password-do-not-print' "${TEST_TMP}/missing-key.log"; then
  fail_test 'Missing-key error printed a credential.'
fi

path_repo_bin="${TEST_TMP}/missing-path-repo-bin"
mkdir -p "$path_repo_bin"
cat > "${path_repo_bin}/curl" <<'COMMAND'
#!/usr/bin/env bash
request="$(cat)"
if [[ "$request" == *'/_nodes/settings?'* ]]; then
  printf '{"nodes":{"node-1":{"settings":{}}}}\n'
else
  printf '{}\n'
fi
COMMAND
chmod 0755 "${path_repo_bin}/curl"
# Elasticsearch login from the environment reaches curl through its stdin config, not the command line.
auth_bin="${TEST_TMP}/auth-bin"
mkdir -p "$auth_bin"
cat > "${auth_bin}/curl" <<'COMMAND'
#!/usr/bin/env bash
printf '%s\n' "$*" > "${TEST_TMP}/curl-args"
cat > "${TEST_TMP}/curl-config"
printf '{}\n'
COMMAND
chmod 0755 "${auth_bin}/curl"
(
  # shellcheck source=deploy/backup/common.sh
  source "${REPO_ROOT}/deploy/backup/common.sh"
  export PATH="${auth_bin}:${PATH}" ELASTICSEARCH_HOST='http://127.0.0.1:19200' ELASTICSEARCH_USERNAME='es-user' ELASTICSEARCH_PASSWORD='es-secret-value'
  elasticsearch_request GET /_cluster/health >/dev/null
  grep -q 'user = "es-user:es-secret-value"' "${TEST_TMP}/curl-config" || fail_test 'Elasticsearch username and password were not sent.'
  grep -q 'es-secret-value' "${TEST_TMP}/curl-args" && fail_test 'The Elasticsearch password reached the curl command line.'
  unset ELASTICSEARCH_USERNAME ELASTICSEARCH_PASSWORD
  ELASTICSEARCH_API_KEY='api-key-value' elasticsearch_request GET /_cluster/health >/dev/null
  grep -q 'Authorization: ApiKey api-key-value' "${TEST_TMP}/curl-config" || fail_test 'The Elasticsearch API key was not sent.'
)

BACKUP_ROOT="${TEST_TMP}/missing-path-repo-backups" PATH="${path_repo_bin}:${PATH}" \
  "$BACKUP_SCRIPT" --namespace=javmeta_it >"${TEST_TMP}/missing-path-repo.log" 2>&1 \
  && fail_test 'Full backup proceeded without Elasticsearch path.repo.'
if ! grep -q 'path.repo is missing' "${TEST_TMP}/missing-path-repo.log"; then
  grep -E '^(ERROR:|jq:|curl:)' "${TEST_TMP}/missing-path-repo.log" \
    | sed -E 's#(https?://)[^/[:space:]@]+:[^/[:space:]@]+@#\1<redacted>@#g' >&2 || true
  fail_test 'Missing path.repo error was not clear.'
fi
assert_missing "${TEST_TMP}/missing-path-repo-backups"

if BACKUP_ROOT="${TEST_TMP}/partial-latest-backups" env -u BACKUP_PUBLISH_LATEST -u ELASTICSEARCH_HOST -u ELASTICSEARCH_SNAPSHOT_PATH \
  "$BACKUP_SCRIPT" --namespace=javmeta_it --postgres-mongo-only >"${TEST_TMP}/partial-latest.log" 2>&1; then
  fail_test 'Partial backup was allowed to publish latest.json.'
fi
grep -q 'cannot publish latest.json' "${TEST_TMP}/partial-latest.log" || fail_test 'Partial latest-manifest guard was not clear.'
assert_missing "${TEST_TMP}/partial-latest-backups"

if "$BACKUP_SCRIPT" --namespace=javmeta --dry-run >"${TEST_TMP}/namespace-guard.log" 2>&1; then
  fail_test 'Shared Docker backup accepted a non-test namespace.'
fi
grep -q 'javmeta_it\*' "${TEST_TMP}/namespace-guard.log" || fail_test 'Shared namespace guard error was not clear.'

if "$RESTORE_SCRIPT" --backup-dir="${TEST_TMP}/none" --namespace=javmeta --dry-run >"${TEST_TMP}/restore-namespace-guard.log" 2>&1; then
  fail_test 'Shared Docker restore accepted a non-test namespace.'
fi
grep -q 'javmeta_it\*' "${TEST_TMP}/restore-namespace-guard.log" || fail_test 'Restore namespace guard error was not clear.'

if ELASTICSEARCH_INDEX_PATTERN='javmeta_it*,other_it*' "$BACKUP_SCRIPT" --namespace=javmeta_it --dry-run >"${TEST_TMP}/index-namespace-guard.log" 2>&1; then
  fail_test 'Backup accepted an Elasticsearch pattern outside the named namespace.'
fi
grep -q 'Every Elasticsearch index pattern' "${TEST_TMP}/index-namespace-guard.log" || fail_test 'Elasticsearch namespace guard error was not clear.'

retention_root="${TEST_TMP}/retention"
export BACKUP_ROOT="$retention_root"
export BACKUP_RETENTION_DAILY='1'
export BACKUP_RETENTION_WEEKLY='1'
mkdir -p \
  "${retention_root}/daily/20261014T000000Z" \
  "${retention_root}/daily/20261015T000000Z" \
  "${retention_root}/daily/20261010T000000Z" \
  "${retention_root}/daily/notes" \
  "${retention_root}/weekly/20261011T000000Z"
printf '{"namespace":"javmeta_it"}\n' > "${retention_root}/daily/20261014T000000Z/manifest.json"
printf '{"namespace":"javmeta_it"}\n' > "${retention_root}/daily/20261015T000000Z/manifest.json"
printf '{"namespace":"other_it"}\n' > "${retention_root}/daily/20261010T000000Z/manifest.json"
printf '{"namespace":"javmeta_it"}\n' > "${retention_root}/weekly/20261011T000000Z/manifest.json"
"$BACKUP_SCRIPT" --namespace=javmeta_it --prune-only >/dev/null
assert_missing "${retention_root}/daily/20261014T000000Z"
assert_exists "${retention_root}/daily/20261015T000000Z"
assert_exists "${retention_root}/daily/20261010T000000Z"
assert_exists "${retention_root}/daily/notes"
assert_exists "${retention_root}/weekly/20261011T000000Z"

mkdir -p "${retention_root}/daily/20261016T000000Z"
printf '{"namespace":"javmeta_it"}\n' > "${retention_root}/daily/20261016T000000Z/manifest.json"
dry_prune_output="$("$BACKUP_SCRIPT" --namespace=javmeta_it --prune-only --dry-run)"
[[ "$dry_prune_output" == DRY\ RUN:* ]] || fail_test 'Dry-run retention did not report its plan.'
assert_exists "${retention_root}/daily/20261015T000000Z"
assert_exists "${retention_root}/daily/20261016T000000Z"

restore_root="${TEST_TMP}/restore"
restore_dir="${restore_root}/daily/20261016T000000Z"
mkdir -p "$restore_dir"
printf 'postgres encrypted fixture\n' > "${restore_dir}/postgres.dump.age"
printf 'mongo encrypted fixture\n' > "${restore_dir}/mongo.archive.age"
printf 'elasticsearch encrypted fixture\n' > "${restore_dir}/elasticsearch.snapshot.tar.gz.age"
cat > "${restore_dir}/manifest.json" <<'MANIFEST'
{
  "namespace": "javmeta_it",
  "created_at": "2026-10-16T00:00:00Z",
  "targets": {
    "postgres": {"database": "javmeta_it", "schema": ""},
    "mongodb": {"database": "javmeta_it", "collection": ""},
    "elasticsearch": {"indices": "javmeta_it*", "snapshot": "snapshot_20261016T000000Z"}
  },
  "artifacts": ["postgres.dump.age", "mongo.archive.age", "elasticsearch.snapshot.tar.gz.age"]
}
MANIFEST
(cd "$restore_dir" && sha256sum postgres.dump.age mongo.archive.age elasticsearch.snapshot.tar.gz.age > manifest.sha256)
cp "${restore_dir}/manifest.sha256" "${TEST_TMP}/manifest.sha256"
export BACKUP_ROOT="$restore_root"
valid_restore_output="$("$RESTORE_SCRIPT" --backup-dir="$restore_dir" --namespace=javmeta_it --dry-run)"
[[ "$valid_restore_output" == DRY\ RUN:* ]] || fail_test 'Restore dry run did not verify a valid manifest.'

restore_preflight_bin="${TEST_TMP}/restore-preflight-bin"
mkdir -p "$restore_preflight_bin"
cat > "${restore_preflight_bin}/psql" <<'COMMAND'
#!/usr/bin/env bash
printf '1\n'
COMMAND
cat > "${restore_preflight_bin}/mongosh" <<'COMMAND'
#!/usr/bin/env bash
eval_expression=''
while (($#)); do
  if [[ "$1" == '--eval' ]]; then
    eval_expression="$2"
    break
  fi
  shift
done
[[ "$eval_expression" == *'print('* ]] || exit 9
printf '0\n'
COMMAND
cat > "${restore_preflight_bin}/curl" <<'COMMAND'
#!/usr/bin/env bash
request="$(cat)"
if [[ "$request" == *'/_nodes/settings?'* ]]; then
  printf '{"nodes":{"node-1":{"settings":{"path.repo":["/tmp/javmeta-it-snapshots"]}}}}\n'
else
  printf '[]\n'
fi
COMMAND
for command_name in age pg_restore mongorestore; do
  cat > "${restore_preflight_bin}/${command_name}" <<COMMAND
#!/usr/bin/env bash
touch "\${TEST_TMP}/${command_name}-called"
exit 0
COMMAND
done
chmod 0755 "${restore_preflight_bin}"/*
PATH="${restore_preflight_bin}:${PATH}"
export PATH
export AGE_IDENTITY="${TEST_TMP}/identity.txt"
printf 'test-only identity\n' > "$AGE_IDENTITY"
if "$RESTORE_SCRIPT" --backup-dir="$restore_dir" --namespace=javmeta_it >"${TEST_TMP}/non-empty-restore.log" 2>&1; then
  fail_test 'Restore accepted non-empty targets without --force-namespace.'
fi
grep -q 'Restore target is not empty' "${TEST_TMP}/non-empty-restore.log" || fail_test 'Non-empty restore target was not reported.'
assert_missing "${TEST_TMP}/age-called"
assert_missing "${TEST_TMP}/pg_restore-called"
assert_missing "${TEST_TMP}/mongorestore-called"

partial_bin="${TEST_TMP}/partial-bin"
mkdir -p "$partial_bin"
cat > "${partial_bin}/pg_dump" <<'COMMAND'
#!/usr/bin/env bash
printf 'postgres test dump\n'
COMMAND
cat > "${partial_bin}/mongodump" <<'COMMAND'
#!/usr/bin/env bash
for argument in "$@"; do
  if [[ "$argument" == '--collection=javmeta_it_fixture' ]]; then
    printf 'mongo test dump\n'
    exit 0
  fi
done
printf 'MongoDB collection filter was missing or incorrect.\n' >&2
exit 9
COMMAND
cat > "${partial_bin}/age" <<'COMMAND'
#!/usr/bin/env bash
mode=''
output=''
input=''
while (($#)); do
  case "$1" in
    --encrypt|--decrypt) mode="$1" ;;
    --output) output="$2"; shift ;;
    --identity|--recipient) shift ;;
    *) input="$1" ;;
  esac
  shift
done
if [[ "$mode" == '--encrypt' ]]; then
  cat > "$output"
else
  cp "$input" "$output"
fi
COMMAND
cat > "${partial_bin}/pg_restore" <<'COMMAND'
#!/usr/bin/env bash
touch "${TEST_TMP}/partial-pg-restore-called"
COMMAND
cat > "${partial_bin}/mongorestore" <<'COMMAND'
#!/usr/bin/env bash
touch "${TEST_TMP}/partial-mongo-restore-called"
COMMAND
cat > "${partial_bin}/psql" <<'COMMAND'
#!/usr/bin/env bash
printf '0\n'
COMMAND
cat > "${partial_bin}/mongosh" <<'COMMAND'
#!/usr/bin/env bash
eval_expression=''
while (($#)); do
  if [[ "$1" == '--eval' ]]; then
    eval_expression="$2"
    break
  fi
  shift
done
[[ "$eval_expression" == *'print('* ]] || exit 9
printf '0\n'
COMMAND
cat > "${partial_bin}/curl" <<'COMMAND'
#!/usr/bin/env bash
touch "${TEST_TMP}/partial-curl-called"
exit 1
COMMAND
chmod 0755 "${partial_bin}"/*
PATH="${partial_bin}:${PATH}"
export PATH
source "${REPO_ROOT}/deploy/backup/common.sh"
[[ "$(mongo_collection_count)" == '0' ]] || fail_test 'MongoDB collection count helper returned an invalid result.'
[[ "$(mongo_collection_exists javmeta_it_fixture)" == '0' ]] || fail_test 'MongoDB collection existence helper returned an invalid result.'
[[ "$(mongo_document_count javmeta_it_fixture)" == '0' ]] || fail_test 'MongoDB document count helper returned an invalid result.'
mongo_drop_collection javmeta_it_fixture >/dev/null || fail_test 'MongoDB collection cleanup helper failed.'
partial_root="${TEST_TMP}/partial-backups"
partial_output="$(BACKUP_ROOT="$partial_root" BACKUP_PUBLISH_LATEST=0 \
  PG_DUMP_SCHEMA=javmeta_it_fixture MONGO_COLLECTION=javmeta_it_fixture \
  env -u ELASTICSEARCH_HOST -u ELASTICSEARCH_SNAPSHOT_PATH \
  "$BACKUP_SCRIPT" --namespace=javmeta_it --postgres-mongo-only)"
partial_dir="$(sed -n 's/^Backup created: //p' <<<"$partial_output" | tail -n 1)"
[[ -n "$partial_dir" ]] || fail_test 'PostgreSQL and MongoDB-only backup did not report its output directory.'
[[ "$(jq -cr '.components' "${partial_dir}/manifest.json")" == '["postgres","mongodb"]' ]] \
  || fail_test 'Partial backup manifest did not list only PostgreSQL and MongoDB.'
[[ "$(jq -cr '.artifacts' "${partial_dir}/manifest.json")" == '["postgres.dump.age","mongo.archive.age"]' ]] \
  || fail_test 'Partial backup manifest listed an unexpected artifact.'
assert_missing "${partial_dir}/elasticsearch.snapshot.tar.gz.age"
assert_missing "${partial_root}/latest.json"
restore_partial_output="$(BACKUP_ROOT="$partial_root" AGE_IDENTITY="$AGE_IDENTITY" \
  PATH="${partial_bin}:${PATH}" env -u ELASTICSEARCH_HOST -u ELASTICSEARCH_SNAPSHOT_PATH \
  "$RESTORE_SCRIPT" --backup-dir="$partial_dir" --namespace=javmeta_it)"
[[ "$restore_partial_output" == *'Elasticsearch not included'* ]] || fail_test 'Partial restore did not label Elasticsearch as excluded.'
assert_exists "${TEST_TMP}/partial-pg-restore-called"
assert_exists "${TEST_TMP}/partial-mongo-restore-called"
assert_missing "${TEST_TMP}/partial-curl-called"

printf '%064d  ../../outside.age\n' 0 > "${restore_dir}/manifest.sha256"
if "$RESTORE_SCRIPT" --backup-dir="$restore_dir" --namespace=javmeta_it --dry-run >"${TEST_TMP}/checksum-path.log" 2>&1; then
  fail_test 'Restore accepted a checksum manifest entry outside the artifact allowlist.'
fi
grep -q 'checksum manifest is invalid' "${TEST_TMP}/checksum-path.log" || fail_test 'Invalid checksum manifest was not reported.'
cp "${TEST_TMP}/manifest.sha256" "${restore_dir}/manifest.sha256"
printf 'corrupt\n' >> "${restore_dir}/postgres.dump.age"
if "$RESTORE_SCRIPT" --backup-dir="$restore_dir" --namespace=javmeta_it --dry-run >"${TEST_TMP}/checksum.log" 2>&1; then
  fail_test 'Restore accepted an artifact with a checksum mismatch.'
fi
grep -q 'checksum verification failed' "${TEST_TMP}/checksum.log" || fail_test 'Checksum failure was not reported.'

printf 'PASS: backup namespace/key guards, restore checksum guard, and scoped retention.\n'

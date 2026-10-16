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

if env -u AGE_RECIPIENT "$BACKUP_SCRIPT" --namespace=javmeta_it --dry-run >"${TEST_TMP}/missing-key.log" 2>&1; then
  fail_test 'Backup accepted a missing age recipient.'
fi
grep -q 'AGE_RECIPIENT' "${TEST_TMP}/missing-key.log" || fail_test 'Missing age recipient error was not clear.'
if grep -q 'test-password-do-not-print' "${TEST_TMP}/missing-key.log"; then
  fail_test 'Missing-key error printed a credential.'
fi

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
printf '0\n'
COMMAND
cat > "${restore_preflight_bin}/curl" <<'COMMAND'
#!/usr/bin/env bash
while IFS= read -r _; do :; done
printf '[]\n'
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

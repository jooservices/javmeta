# JAVM-47 Backup and Restore Rehearsal

**Run date:** 2026-10-16 (UTC)

**Result:** PostgreSQL and MongoDB rehearsal passed; Elasticsearch remains pending under JOOS-114.

## Scope and method

Ran `rehearse.sh --namespace=javmeta_it --postgres-mongo-only` in the task-local backup container against shared test targets. PostgreSQL used database `javmeta_it`; MongoDB used database `javmeta_it_archive`. The command created three-row/document fixtures, produced an age-encrypted backup, restored it, checked counts, then cleaned its fixtures and backup directory.

Credentials came from the private root `.env` through a mode-0600 task-local environment file. Values were suppressed from command output and removed after verification.

The final run used the checkout's current `common.sh`, `backup.sh`, `restore.sh`, and `rehearse.sh` mounted read-only into the container. The invocation was:

```sh
docker run --rm \
  --env-file "$PRIVATE_REHEARSAL_ENV" \
  --volume "$PRIVATE_BACKUP_DIR:/backups" \
  --volume "$PRIVATE_AGE_DIR:/run/age:ro" \
  javmeta-backup:<task-local> \
  rehearse.sh --namespace=javmeta_it --postgres-mongo-only
```

## Results

| Check | Result | Time | Evidence |
| --- | --- | ---: | --- |
| PostgreSQL seed | PASS | 827 ms | expected 3 rows; actual 3 |
| MongoDB seed | PASS | 2,171 ms | expected 3 documents; actual 3 |
| Encrypted PostgreSQL + MongoDB backup | PASS | 208 ms | age-encrypted artifacts created |
| Restore | PASS | 949 ms | both database artifacts restored |
| PostgreSQL restore check | PASS | 70 ms | expected 3 rows; actual 3 |
| MongoDB restore check | PASS | 107,901 ms | expected 3 documents; actual 3 |
| Elasticsearch seed and restore | PENDING | — | JOOS-114: `path.repo` prerequisite unavailable |
| Overall | PARTIAL PASS | — | PostgreSQL and MongoDB passed; Elasticsearch not included |

The Elasticsearch snapshot path was not accessed and no Elasticsearch fixture or snapshot was created. A live node-settings inspection could not be completed, so the full rehearsal remains pending. Full backup, restore, and rehearsal preflight `path.repo` and fail with an explicit JOOS-114 message when it is unavailable or does not allow the requested snapshot path. Shell coverage exercises the missing-`path.repo` failure before backup output is created.

## Defects found and fixed

- `mongosh --eval` returned no printed expression value, causing rehearsal checks to treat an empty result as a failure. Count and existence expressions now print their values explicitly.
- Mixed-case timestamp characters in the generated PostgreSQL schema did not match `pg_dump`'s unquoted schema pattern. Rehearsal fixture names now stay lowercase.
- MongoDB cleanup called a nonexistent `db.dropCollection` method. Cleanup now drops the generated collection through `db.getCollection(...).drop()` and verifies absence. A task-owned collection from the failed run was identified and removed; PostgreSQL and MongoDB fixtures from the final run were cleaned by the rehearsal trap.

## Local CI

Command: `JAVMETA_CI_SKIP_BUILD=1 docker/ci/run composer ci`

Result: exit 0.

- PHPStan: no errors.
- PHP CS Fixer: 0 of 320 files needed changes.
- PHPUnit: 569 passed, 4,262 assertions.
- Coverage: 90.90% (3,988 / 4,387 statements), above the 90.00% minimum.
- Worker queue, CaptainHook, backup shell tests, and ShellCheck: passed.

The MongoDB Database Tools emitted a `--db` / `--collection` deprecation note during restore; the restore completed with 3 documents and 0 failures.

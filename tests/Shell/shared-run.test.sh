#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
root="$(cd -- "$script_dir/../.." && pwd)"
runner="$root/docker/dev/shared-run"
fixture="$(mktemp -d "$root/.tmp/shared-run-test.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT

if output="$(python3 -I "$runner" --mode wt:t08 2>&1)"; then
  echo 'shared-run must require a command' >&2
  exit 1
fi
[[ "$output" == *'a command and --mode'* ]] || { echo 'unexpected missing-command failure' >&2; exit 1; }

workspaces="$fixture/workspaces"
mkdir -p "$workspaces/scripts/docker" "$workspaces/.tmp"
chmod 700 "$workspaces/.tmp"
cat > "$workspaces/.env" <<'ENV'
JAVMETA_IT_POSTGRES_USER=jvmeta_it
JAVMETA_IT_POSTGRES_DATABASE=jvmeta_it
JAVMETA_IT_POSTGRES_PASSWORD=integration-pg-fixture
JAVMETA_IT_MONGODB_USER=jvmeta_it
JAVMETA_IT_MONGODB_DATABASE=javmeta_it_archive
JAVMETA_IT_MONGODB_AUTH_DATABASE=javmeta_it_archive
JAVMETA_IT_MONGODB_PASSWORD=integration-mongo-fixture
JAVMETA_IT_ELASTIC_USER=jvmeta_it
JAVMETA_IT_ELASTIC_INDEX_PATTERN=javmeta_it_*
JAVMETA_IT_ELASTIC_PASSWORD=integration-elastic-fixture
JAVMETA_IT_OPENOBSERVE_ORG=javmeta_it
JAVMETA_IT_OPENOBSERVE_STREAM=javmeta_it_logs
JAVMETA_IT_OPENOBSERVE_EMAIL=javmeta-it@example.invalid
JAVMETA_IT_OPENOBSERVE_PASSWORD=integration-openobserve-fixture
JAVMETA_IT_RESOURCE_MARKER=integration-marker-fixture
ENV
chmod 600 "$workspaces/.env"
cp "$workspaces/.env" "$fixture/valid.env"

cat > "$workspaces/scripts/docker/shared" <<'SHARED'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1" == env-project && "$2" == javmeta ]] || exit 2
shift 2
mode=""
output=""
while (($#)); do
  case "$1" in
    --mode) mode="$2"; shift 2 ;;
    --output) output="$2"; shift 2 ;;
    *) exit 2 ;;
  esac
done
cat > "$output" <<ENV
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=javmeta_wtt08
DB_USERNAME=postgres-root-fixture
DB_PASSWORD=postgres-root-password-fixture
MONGODB_URI=mongodb://mongo-root-fixture:mongo-root-password-fixture@mongodb:27017/?authSource=admin
MONGO_DATABASE=javmeta_wtt08
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=redis-root-password-fixture
REDIS_DB=3
REDIS_CACHE_DB=3
REDIS_PREFIX=javmeta_wtt08:
ENV
SHARED
chmod +x "$workspaces/scripts/docker/shared"

cat > "$workspaces/scripts/docker/runtime" <<'RUNTIME'
#!/usr/bin/env bash
set -euo pipefail
python3 - "$CAPTURE_FILE" <<'PY'
import os
import sys
from pathlib import Path

keys = (
    "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD", "MONGO_DATABASE", "MONGO_URI",
    "ELASTICSEARCH_USERNAME", "ELASTICSEARCH_PASSWORD", "ELASTICSEARCH_MOVIES_INDEX",
    "OPENOBSERVE_ORG", "OPENOBSERVE_EMAIL", "OPENOBSERVE_PASSWORD", "OPENOBSERVE_STREAM_LOGS",
    "OPENOBSERVE_STREAM_METRICS", "REDIS_HOST", "REDIS_PORT", "REDIS_USERNAME", "REDIS_PASSWORD",
    "REDIS_PREFIX", "HORIZON_PREFIX",
)
Path(sys.argv[1]).write_text("\n".join(f"{key}={os.environ.get(key, '')}" for key in keys) + "\n")
PY
if [[ -n "${RUNTIME_LOG:-}" ]]; then
  printf '%s\n' start >> "$RUNTIME_LOG"
  sleep 0.1
  printf '%s\n' end >> "$RUNTIME_LOG"
fi
RUNTIME
chmod +x "$workspaces/scripts/docker/runtime"

run_shared() {
  CAPTURE_FILE="$fixture/captured-env" JAVMETA_WORKSPACES_ROOT="$workspaces" \
    python3 -I "$runner" --mode wt:t08 --integration "$@" -- true
}

run_shared
captured="$fixture/captured-env"
grep -Fxq 'DB_DATABASE=jvmeta_it' "$captured" || { echo 'integration Postgres database was not selected' >&2; exit 1; }
grep -Fxq 'DB_USERNAME=jvmeta_it' "$captured" || { echo 'integration Postgres identity was not selected' >&2; exit 1; }
grep -Fxq 'DB_PASSWORD=integration-pg-fixture' "$captured" || { echo 'integration Postgres credential was not selected' >&2; exit 1; }
grep -Fxq 'MONGO_DATABASE=javmeta_it_archive' "$captured" || { echo 'integration Mongo database was not selected' >&2; exit 1; }
grep -Fxq 'MONGO_URI=mongodb://jvmeta_it:integration-mongo-fixture@mongodb:27017/javmeta_it_archive?authSource=javmeta_it_archive' "$captured" \
  || { echo 'integration Mongo identity was not scoped to its database' >&2; exit 1; }
grep -Fxq 'ELASTICSEARCH_USERNAME=jvmeta_it' "$captured" || { echo 'integration Elasticsearch identity was not selected' >&2; exit 1; }
grep -Fxq 'ELASTICSEARCH_PASSWORD=integration-elastic-fixture' "$captured" || { echo 'integration Elasticsearch credential was not selected' >&2; exit 1; }
grep -Fxq 'ELASTICSEARCH_MOVIES_INDEX=javmeta_it_wtt08_movies' "$captured" || { echo 'integration Elasticsearch index was not worktree-scoped' >&2; exit 1; }
grep -Fxq 'OPENOBSERVE_ORG=javmeta_it' "$captured" || { echo 'integration OpenObserve org was not selected' >&2; exit 1; }
grep -Fxq 'OPENOBSERVE_STREAM_LOGS=javmeta_it_logs_wtt08' "$captured" || { echo 'integration OpenObserve stream was not worktree-scoped' >&2; exit 1; }
grep -Fxq 'OPENOBSERVE_PASSWORD=integration-openobserve-fixture' "$captured" || { echo 'integration OpenObserve credential was not selected' >&2; exit 1; }
grep -Fxq 'REDIS_HOST=127.0.0.1' "$captured" || { echo 'unprovisioned Redis must not resolve to the shared service' >&2; exit 1; }
grep -Fxq 'REDIS_PORT=1' "$captured" || { echo 'unprovisioned Redis identity must remain unavailable' >&2; exit 1; }
grep -Fxq 'REDIS_PASSWORD=' "$captured" || { echo 'shared Redis credentials reached integration runtime' >&2; exit 1; }
for root_secret in postgres-root-password-fixture mongo-root-password-fixture redis-root-password-fixture; do
  if grep -Fq "$root_secret" "$captured"; then
    echo 'a shared root credential reached the integration runtime' >&2
    exit 1
  fi
done

if output="$(run_shared --require-redis 2>&1)"; then
  echo 'Redis-dependent integration must refuse a missing Redis test identity' >&2
  exit 1
fi
[[ "$output" == *'JAVMETA_IT Redis identity is not provisioned'* ]] || { echo 'missing Redis identity failure was not clear' >&2; exit 1; }

cat >> "$workspaces/.env" <<'ENV'
JAVMETA_IT_REDIS_USER=jvmeta_it
JAVMETA_IT_REDIS_PASSWORD=integration-redis-fixture
JAVMETA_IT_REDIS_HOST=127.0.0.1
JAVMETA_IT_REDIS_PORT=16382
JAVMETA_IT_REDIS_PREFIX=javmeta_it:
ENV
chmod 600 "$workspaces/.env"
run_shared --require-redis
grep -Fxq 'REDIS_HOST=redis' "$captured" || { echo 'provisioned Redis host was not selected' >&2; exit 1; }
grep -Fxq 'REDIS_PORT=6379' "$captured" || { echo 'provisioned Redis port was not selected' >&2; exit 1; }
grep -Fxq 'REDIS_USERNAME=jvmeta_it' "$captured" || { echo 'least-privilege Redis username was not selected' >&2; exit 1; }
grep -Fxq 'REDIS_PASSWORD=integration-redis-fixture' "$captured" || { echo 'provisioned Redis credential was not selected' >&2; exit 1; }
grep -Fxq 'REDIS_PREFIX=javmeta_it:wt_t08:' "$captured" || { echo 'Redis keys were not worktree-scoped' >&2; exit 1; }
grep -Fxq 'HORIZON_PREFIX=javmeta_it:wt_t08:horizon:' "$captured" || { echo 'Horizon keys were not worktree-scoped' >&2; exit 1; }

lock_log="$fixture/integration-lock.log"
RUNTIME_LOG="$lock_log" CAPTURE_FILE="$fixture/first-env" JAVMETA_WORKSPACES_ROOT="$workspaces" \
  python3 -I "$runner" --mode wt:t08 --integration -- true &
first_run=$!
RUNTIME_LOG="$lock_log" CAPTURE_FILE="$fixture/second-env" JAVMETA_WORKSPACES_ROOT="$workspaces" \
  python3 -I "$runner" --mode wt:t08 --integration -- true &
second_run=$!
wait "$first_run"
wait "$second_run"
mapfile -t lock_events < "$lock_log"
[[ "${#lock_events[@]}" -eq 4 ]] || { echo 'concurrent shared-run invocations did not both complete' >&2; exit 1; }
if [[ "${lock_events[*]}" != 'start end start end' ]]; then
  printf 'Shared integration lock did not serialize direct shared-run calls: %s\n' "${lock_events[*]}" >&2
  exit 1
fi
[[ "$(stat -c '%a' "$workspaces/.tmp/javmeta-integration.lock")" == 600 ]] || { echo 'shared integration lock file must be private' >&2; exit 1; }

missing_identity="$fixture/missing-identity.env"
grep -v '^JAVMETA_IT_ELASTIC_PASSWORD=' "$workspaces/.env" > "$missing_identity"
cp "$missing_identity" "$workspaces/.env"
chmod 600 "$workspaces/.env"
rm -f -- "$captured"
if output="$(run_shared 2>&1)"; then
  echo 'shared-run must refuse to execute with incomplete integration identity' >&2
  exit 1
fi
[[ "$output" == *'JAVMETA_IT_* credentials are incomplete'* ]] || { echo 'missing identity failure was not clear' >&2; exit 1; }
[[ ! -e "$captured" ]] || { echo 'runtime started without a complete integration identity' >&2; exit 1; }

cp "$fixture/valid.env" "$workspaces/.env"
cat >> "$workspaces/.env" <<'ENV'
JAVMETA_IT_POSTGRES_USER=jvmeta_it
ENV
chmod 600 "$workspaces/.env"
rm -f -- "$captured"
if output="$(run_shared 2>&1)"; then
  echo 'shared-run must refuse duplicate integration identity settings' >&2
  exit 1
fi
[[ "$output" == *'Duplicate least-privilege JavMeta integration settings'* ]] || { echo 'duplicate identity failure was not clear' >&2; exit 1; }
[[ ! -e "$captured" ]] || { echo 'runtime started with duplicate integration identity settings' >&2; exit 1; }

echo 'shared-run integration identity tests: PASS'

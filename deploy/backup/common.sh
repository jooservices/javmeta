#!/usr/bin/env bash

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

require_env() {
  local name="$1"

  [[ -n "${!name:-}" ]] || fail "Required environment variable is missing: ${name}."
}

validate_namespace() {
  local namespace="$1"

  [[ "$namespace" =~ ^[a-z][a-z0-9_-]{0,62}$ ]] || fail 'Namespace must be lowercase and contain only letters, digits, underscores, or hyphens.'

  if [[ "${JAVMETA_SHARED_DOCKER:-0}" == '1' && "$namespace" != javmeta_it* && "$namespace" != javmeta_test* && "$namespace" != javmeta_wt* ]]; then
    fail 'Shared Docker writes are restricted to javmeta_it*, javmeta_test* and javmeta_wt* namespaces.'
  fi
}

mongo_database_from_uri() {
  local uri="$1"
  local without_query

  without_query="${uri%%\?*}"
  without_query="${without_query%%\#*}"
  printf '%s' "${without_query##*/}"
}

validate_namespace_targets() {
  local namespace="$1"
  local mongo_database="$2"
  local index_pattern="$3"
  local index
  local -a indices=()

  validate_database_namespace_targets "$namespace" "$mongo_database"
  IFS=',' read -r -a indices <<<"$index_pattern"
  (( ${#indices[@]} > 0 )) || fail 'At least one Elasticsearch index pattern is required.'
  for index in "${indices[@]}"; do
    [[ "$index" == "$namespace"* ]] || fail 'Every Elasticsearch index pattern must start with the explicitly named backup namespace.'
    [[ "$index" =~ ^[A-Za-z0-9_.\*-]+$ ]] || fail 'Elasticsearch index pattern contains unsupported characters.'
  done
}

validate_database_namespace_targets() {
  local namespace="$1"
  local mongo_database="$2"

  [[ "${PGDATABASE}" == "$namespace"* ]] || fail 'PGDATABASE must start with the explicitly named backup namespace.'
  [[ "$mongo_database" == "$namespace"* ]] || fail 'MONGO_URI database must start with the explicitly named backup namespace.'
}

curl_config_escape() {
  local value="$1"

  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  printf '%s' "$value"
}

elasticsearch_request() {
  local method="$1"
  local endpoint="$2"
  local body="${3:-}"
  local url="${ELASTICSEARCH_HOST%/}${endpoint}"

  {
    printf 'url = "%s"\n' "$(curl_config_escape "$url")"
    printf 'request = "%s"\n' "$method"
    printf 'silent\nshow-error\nfail\nmax-time = %s\n' "${ELASTICSEARCH_TIMEOUT:-60}"
    # Credentials travel in this curl config on stdin, never on the command line. A login inside ELASTICSEARCH_HOST still works.
    if [[ -n "${ELASTICSEARCH_API_KEY:-}" ]]; then
      printf 'header = "Authorization: ApiKey %s"\n' "$(curl_config_escape "$ELASTICSEARCH_API_KEY")"
    elif [[ -n "${ELASTICSEARCH_USERNAME:-}" && -n "${ELASTICSEARCH_PASSWORD:-}" ]]; then
      printf 'user = "%s:%s"\n' "$(curl_config_escape "$ELASTICSEARCH_USERNAME")" "$(curl_config_escape "$ELASTICSEARCH_PASSWORD")"
    fi
    if [[ -n "$body" ]]; then
      printf 'header = "Content-Type: application/json"\n'
      printf 'data = "%s"\n' "$(curl_config_escape "$body")"
    fi
  } | curl --config -
}

validate_snapshot_path() {
  local path="$1"

  [[ "$path" == /* && "$path" != '/' ]] || fail 'ELASTICSEARCH_SNAPSHOT_PATH must be a non-root absolute path shared with every Elasticsearch node.'
  [[ "$path" != *'/../'* && "$path" != */.. ]] || fail 'ELASTICSEARCH_SNAPSHOT_PATH must not contain parent-directory segments.'
}

require_elasticsearch_snapshot_path_repo() {
  local path="$1"
  local response

  response="$(elasticsearch_request GET '/_nodes/settings?filter_path=nodes.*.settings.path.repo' 2>/dev/null)" \
    || fail 'Could not inspect Elasticsearch node settings for path.repo; snapshot backup and restore remain pending (JOOS-114).'

  jq -e --arg requested "$path" '
    (.nodes // {}) as $nodes
    | ($nodes | length) > 0
      and all($nodes[];
        (.settings["path.repo"] // []) as $repositories
        | ($repositories | type) == "array"
          and any($repositories[];
            . as $repository
            | ($repository | type) == "string"
              and (($repository | sub("/+$"; "")) as $root
                | $requested == $root or ($requested | startswith($root + "/")))
          )
      )
  ' >/dev/null <<<"$response" \
    || fail 'Elasticsearch path.repo is missing or does not include ELASTICSEARCH_SNAPSHOT_PATH on every node; snapshot backup and restore remain pending (JOOS-114).'
}

postgres_user_object_count() {
  PGPASSWORD="$PGPASSWORD" psql \
    --no-password \
    --host="$PGHOST" \
    --port="$PGPORT" \
    --username="$PGUSER" \
    --dbname="$PGDATABASE" \
    --tuples-only \
    --no-align \
    --set=ON_ERROR_STOP=1 \
    --command="SELECT (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast') AND c.relkind IN ('r', 'p', 'f', 'v', 'm', 'S')) + (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast')) + (SELECT count(*) FROM pg_namespace WHERE nspname NOT IN ('pg_catalog', 'information_schema', 'pg_toast', 'public'));"
}

mongo_collection_count() {
  mongosh --quiet "$MONGO_URI" --eval "print(db.getCollectionNames().filter((name) => !name.startsWith('system.')).length)"
}

mongo_collection_exists() {
  local collection="$1"

  [[ "$collection" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'MongoDB collection name is invalid.'
  mongosh --quiet "$MONGO_URI" --eval "print(db.getCollectionNames().includes('$collection') ? 1 : 0)"
}

mongo_document_count() {
  local collection="$1"

  [[ "$collection" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'MongoDB collection name is invalid.'
  mongosh --quiet "$MONGO_URI" --eval "print(db.getCollection('$collection').countDocuments())"
}

mongo_drop_collection() {
  local collection="$1"
  local remaining

  [[ "$collection" =~ ^[A-Za-z0-9_-]+$ ]] || fail 'MongoDB collection name is invalid.'
  remaining="$(mongosh --quiet "$MONGO_URI" --eval "db.getCollection('$collection').drop(); print(db.getCollectionNames().includes('$collection') ? 1 : 0)")"
  [[ "$remaining" == '0' ]] || fail 'MongoDB rehearsal collection cleanup did not remove the collection.'
}

elasticsearch_index_count() {
  local pattern="$1"
  local response

  response="$(elasticsearch_request GET "/_cat/indices/${pattern}?format=json&h=index")" || fail 'Could not inspect the explicitly named Elasticsearch index namespace.'
  jq -er 'length' <<<"$response"
}

#!/usr/bin/env bash
# Provision with administrator Basic Auth. The API key is written only to a private operator path.
set -euo pipefail
set +x
umask 077
: "${ELASTICSEARCH_HOST:?Set the HTTPS Elasticsearch URL}"
[[ "$ELASTICSEARCH_HOST" == https://* ]] || { echo 'ELASTICSEARCH_HOST must use https://.' >&2; exit 1; }
: "${ES_ADMIN_USER:?Set the administrator username}"
: "${ES_KEY_FILE:?Set a private absolute file path outside the checkout}"
[[ "$ES_KEY_FILE" == /* && ! -L "$ES_KEY_FILE" ]] || { echo 'Invalid private key file path.' >&2; exit 1; }
if [[ -z "${ES_ADMIN_PASSWORD:-}" ]]; then
  read -rs -p 'Elasticsearch administrator password: ' ES_ADMIN_PASSWORD
  printf '\n' >&2
fi
[[ -n "$ES_ADMIN_PASSWORD" ]] || exit 1
# Keep credentials out of process arguments and temporary files.
request() {
  local auth
  auth="$(printf '%s:%s' "$ES_ADMIN_USER" "$ES_ADMIN_PASSWORD" | base64 | tr -d '\n')"
  printf 'header = "Authorization: Basic %s"\n' "$auth" | curl --config - \
    --silent --show-error --fail --connect-timeout 10 --max-time 60 \
    -H 'Content-Type: application/json' "$@"
}
checkout="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd)"
key_path="$(realpath -m "$ES_KEY_FILE")"
[[ "$key_path" != "$checkout"/* ]] || { echo "Key delivery must be outside the checkout." >&2; exit 1; }
url="${ELASTICSEARCH_HOST%/}"
role='{"cluster":["monitor"],"indices":[{"names":["xcrawler_*"],"privileges":["read","write","create_index","manage"]}]}'
request -X PUT "$url/_security/role/xcrawler" -d "$role" >/dev/null
for kind in movies performers scenes; do
  case "$kind" in movies) field=embedding_movie ;; performers) field=embedding_performer ;; scenes) field=embedding ;; esac
  properties="\"$field\":{\"type\":\"dense_vector\",\"dims\":384,\"index\":true,\"similarity\":\"cosine\"}"
  if [[ "$kind" == scenes ]]; then properties="$properties,\"description\":{\"type\":\"text\"}"; else properties="$properties,\"uuid\":{\"type\":\"keyword\"}"; fi
  status="$(request --no-fail -o /dev/null -w '%{http_code}' -I "$url/xcrawler_$kind" || true)"
  if [[ "$status" == 404 ]]; then
    request -X PUT "$url/xcrawler_$kind" -d "{\"settings\":{\"number_of_shards\":1,\"number_of_replicas\":0},\"mappings\":{\"properties\":{$properties}}}" >/dev/null
  elif [[ "$status" == 200 ]]; then
    request -X PUT "$url/xcrawler_$kind/_mapping" -d "{\"properties\":{$properties}}" >/dev/null
  else
    echo 'Elasticsearch index lookup failed.' >&2; exit 1
  fi
done
if [[ -s "$ES_KEY_FILE" ]]; then
  chmod 600 "$ES_KEY_FILE"
  # Reuse rather than rotate a live key. Validate identity and effective privileges in the integration proof.
  echo 'Existing API key file retained; no key rotated.'
else
  # Refuse to create an orphan duplicate if a prior key was lost: operator must explicitly invalidate it first.
  count="$(request "$url/_security/api_key?name=xcrawler" | jq -r '[.api_keys[] | select(.invalidated == false)] | length')"
  [[ "$count" == 0 ]] || { echo 'An active xcrawler key already exists. Recover its secret or invalidate it explicitly.' >&2; exit 1; }
  (set -o noclobber; request -X POST "$url/_security/api_key" \
    -d "{\"name\":\"xcrawler\",\"role_descriptors\":{\"xcrawler\":$role}}" | jq -er '.encoded' > "$ES_KEY_FILE")
  [[ -s "$ES_KEY_FILE" ]] || exit 1
  echo 'API key delivered to the private operator file.'
fi

# Reject stale/wrong key delivery files without exposing their contents.
key="$(cat "$ES_KEY_FILE")"
authentication="$(printf 'header = "Authorization: ApiKey %s"\n' "$key" | curl --config - \
  --silent --show-error --fail --connect-timeout 10 --max-time 60 \
  "$url/_security/_authenticate")"
key_id="$(jq -er 'select(.api_key.name == "xcrawler") | .api_key.id' <<< "$authentication")"
# Reconcile a retained key's descriptors too: a same-named older key may have broader privileges.
request -X PUT "$url/_security/api_key/$key_id" -d "{\"role_descriptors\":{\"xcrawler\":$role}}" >/dev/null
unset key authentication ES_ADMIN_PASSWORD

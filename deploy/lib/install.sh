#!/usr/bin/env bash
# Shared implementation for interactive installation and unattended releases.
# shellcheck disable=SC2154 # root is set by the entry point before sourcing.
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
log() { printf '%s\n' "$*"; }
step() {
  local label="$1" started=$SECONDS
  shift
  log "START: $label"
  if [[ "$1" == unit_action ]]; then "$@"; else "$@" >/dev/null 2>&1; fi
  log "OK: $label ($((SECONDS-started))s), exit status verified"
}
ask() {
  local key="$1" label="$2" secret="$3" value
  if [[ "$secret" == true ]]; then
    printf '%s [hidden; Enter keeps existing value]: ' "$label" >&2
    IFS= read -rs value <&3 || die "Input ended at $key"
    printf '\n' >&2
  else
    printf '%s [%s]: ' "$label" "${answers[$key]:-}" >&2
    IFS= read -r value <&3 || die "Input ended at $key"
  fi
  [[ -z "$value" ]] || answers[$key]="$value"
}
load_answers() {
  local config="$1" row key value
  if [[ -n "$config" ]]; then
    [[ -f "$config" && ! -L "$config" && "$(stat -c '%a' "$config")" == 600 && -O "$config" ]] || die '--config must be an owned regular 0600 file.'
    if git -C "$root" ls-files --error-unmatch -- "$(realpath -- "$config")" >/dev/null 2>&1; then die 'Config must never be tracked in Git.'; fi
    jq -e 'type == "object" and all(.[]; type == "string")' "$config" >/dev/null || die 'Config must be a JSON object of strings.'
    while IFS= read -r row; do
      key="$(jq -r '.key' <<< "$row")"
      [[ -v "allowed[$key]" ]] || die "Unknown config key: $key"
      value="$(jq -r '.value' <<< "$row")"
      answers[$key]="$value"
    done < <(jq -c 'to_entries[]' "$config")
  fi
}
collect_answers() {
  local row key value required secret label missing=() service enabled
  while IFS='|' read -r key value; do
    allowed[$key]=1
    if [[ -v "$key" ]]; then answers[$key]="${!key}"; else answers[$key]="$value"; fi
  done < <(jq -r '.options[], .services[].fields[] | [.key, .default] | join("|")' "$manifest")
  while IFS= read -r service; do
    key="SERVICE_${service^^}_ENABLED"
    allowed[$key]=1
    answers[$key]="${!key:-0}"
  done < <(jq -r '.services[] | select(.optional) | .id' "$manifest")
  load_answers "$config"
  for key in "${!flags[@]}"; do
    [[ -v "allowed[$key]" ]] || die "Unknown flag key: $key"
    answers[$key]="${flags[$key]}"
  done
  if [[ -n "${answers[TARGET_DIR]}" ]]; then
    for key in "${!answers[@]}"; do answers[$key]="${answers[$key]//@TARGET_DIR@/${answers[TARGET_DIR]}}"; done
  fi
  if (( ! non_interactive )) && [[ -z "$config" ]]; then
    while IFS= read -r service; do
      key="SERVICE_${service^^}_ENABLED"
      ask "$key" "Enable optional $service service? (1/0)" false
    done < <(jq -r '.services[] | select(.optional) | .id' "$manifest")
  fi
  for key in REDIS_PREFIX CACHE_PREFIX HORIZON_PREFIX; do answers[$key]="${answers[$key]//@APP_NAME@/${answers[APP_NAME]}}"; done
  # Existing env wins over defaults; changes to explicit answers need consent.
  if [[ -n "${answers[TARGET_DIR]}" && -f "${answers[TARGET_DIR]}/shared/.env" ]]; then
    while IFS='=' read -r key value; do
      [[ -v "allowed[$key]" && "$key" =~ ^[A-Z][A-Z0-9_]*$ ]] || continue
      # Generated values use single-quoted dotenv strings. Never source operator input.
      if [[ "$value" == \"*\" ]]; then
        value="${value:1:${#value}-2}"
        value="${value//\\\$/\$}"
        value="${value//\\\"/\"}"
        value="${value//\\\\/\\}"
      elif [[ "$value" == \'*\' ]]; then value="${value:1:${#value}-2}"; fi
      # An empty APP_KEY requests generation only on first install, never rotation.
      if [[ "$key" == APP_KEY && -z "${answers[$key]}" ]]; then answers[$key]="$value"; continue; fi
      if [[ "${answers[$key]}" != "$value" ]]; then
        if [[ -v "flags[$key]" ]] || { [[ -n "$config" ]] && jq -e --arg k "$key" 'has($k)' "$config" >/dev/null; }; then
          if (( ! replace_existing )); then
            (( ! non_interactive )) || die "Existing $key differs; use --replace-existing to authorize changes."
            printf 'Change existing %s? [y/N]: ' "$key" >&2
            IFS= read -r consent <&3 || die 'Input ended.'
            [[ "$consent" == y ]] || answers[$key]="$value"
          fi
        else
          answers[$key]="$value"
        fi
      fi
    done < "${answers[TARGET_DIR]}/shared/.env"
  fi
  while IFS='|' read -r key label secret required service; do
    answers[$key]="${answers[$key]//@TARGET_DIR@/${answers[TARGET_DIR]}}"
    if [[ -n "$service" ]]; then
      enabled="SERVICE_${service^^}_ENABLED"
      [[ "${answers[$enabled]:-1}" == 1 ]] || continue
    fi
    if (( ! non_interactive )) && [[ ! -v "flags[$key]" && ! -v "$key" ]] && [[ -z "$config" || ( "$required" == true && -z "${answers[$key]}" ) ]]; then ask "$key" "$label" "$secret"; fi
    if [[ "$required" == true && -z "${answers[$key]}" ]]; then missing+=("$key"); fi
  done < <(jq -r '.options[], (.services[] | . as $s | .fields[] + {service:$s.id}) | [.key,.label,.secret,.required,(.service // "")] | map(tostring) | join("|")' "$manifest")
  if (( ${#missing[@]} )); then die "Missing answers: ${missing[*]}"; fi
  for key in "${!answers[@]}"; do
    [[ "${answers[$key]}" != *$'\n'* && "${answers[$key]}" != *$'\r'* ]] || die "Multiline answer rejected: $key"
    if [[ "$key" == SERVICE_*_ENABLED ]]; then [[ "${answers[$key]}" =~ ^[01]$ ]] || die "Use 1 or 0 for $key"; fi
  done
  [[ "${answers[TARGET_DIR]}" == /* && "${answers[TARGET_DIR]}" != / && ! -L "${answers[TARGET_DIR]}" ]] || die 'TARGET_DIR must be an absolute directory, not / or a symlink.'
  [[ "${answers[REF]}" != -* && "${answers[REPO_URL]}" != -* ]] || die 'Repository/ref cannot start with a dash.'
  for key in FPM_UNIT NGINX_UNIT; do
    [[ "${answers[$key]}" =~ ^[a-zA-Z0-9_][a-zA-Z0-9@_.-]*$ ]] || die "Invalid unit: $key"
  done
  [[ "${answers[UNIT_PREFIX]}" =~ ^[a-zA-Z0-9_-]+$ ]] || die 'Invalid unit prefix.'
  [[ "${answers[READINESS_TIMEOUT]}" =~ ^[1-9][0-9]*$ ]] || die 'Invalid READINESS_TIMEOUT.'
  [[ "${answers[APP_URL]}" =~ ^https?:// ]] || die 'APP_URL needs an HTTP(S) scheme.'
  # Credentials in repository URLs leak through Git diagnostics and process listings.
  [[ "${answers[REPO_URL]}" != *://*@* ]] || die 'Use a credential helper, not credentials in REPO_URL.'
  for key in REDIS_PREFIX CACHE_PREFIX HORIZON_PREFIX; do answers[$key]="${answers[$key]//@APP_NAME@/${answers[APP_NAME]}}"; done
  [[ "${answers[NODE_ROLE]}" =~ ^(primary|worker)$ ]] || die 'NODE_ROLE must be primary or worker.'
  [[ "${answers[JAVMETA_WORKERS_PER_QUEUE]}" =~ ^[1-9][0-9]*$ ]] || die 'Invalid worker count.'
  answers[CRAWLERX_PLAYWRIGHT_SCRIPT]="${answers[TARGET_DIR]}/current/vendor/jooservices/crawlerx/scripts/playwright-fetch.mjs"
  answers[CREDENTIALS_FILE]="${answers[CREDENTIALS_FILE]//@APP_NAME@/${answers[APP_NAME]}}"
  [[ "${answers[CREDENTIALS_FILE]}" == /* && ! -L "${answers[CREDENTIALS_FILE]}" ]] || die 'Invalid credentials file.'
  env_digest='absent'
  if [[ -f "${answers[TARGET_DIR]}/shared/.env" ]]; then env_digest="$(sha256sum "${answers[TARGET_DIR]}/shared/.env")"; fi
}
validate_services() {
  local row id optional enabled kind key started reason
  while IFS= read -r row; do
    id="$(jq -r '.id' <<< "$row")"
    kind="$(jq -r '.validation' <<< "$row")"
    optional="$(jq -r '.optional' <<< "$row")"
    enabled="SERVICE_${id^^}_ENABLED"
    if [[ "$optional" == true && "${answers[$enabled]}" != 1 ]]; then log "SKIP: $id disabled by operator"; continue; fi
    [[ "$kind" != none ]] || continue
    started=$SECONDS
    while ! reason="$(for key in "${!answers[@]}"; do export "$key=${answers[$key]}"; done; php "$root/deploy/lib/probe.php" "$kind" "$id" 2>/dev/null)"; do
      log "FAILED: $id TCP/authenticated probe ($((SECONDS-started))s): ${reason:-client unavailable}"
      (( ! non_interactive )) || die "Service validation failed: $id"
      if [[ "$id" == postgres && "$reason" == *timezone* ]]; then
        printf 'Repair database timezone using runtime admin credentials? [y/N]: ' >&2
        IFS= read -r consent <&3 || die 'Input ended.'
        if [[ "$consent" == y ]]; then repair_timezone || die 'Timezone repair failed (credentials redacted)'; continue; fi
      fi
      printf 'Correct the service answers and retry? [y/N]: ' >&2
      IFS= read -r consent <&3 || die 'Input ended.'
      [[ "$consent" == y ]] || die "Service validation aborted: $id"
      while IFS= read -r field; do
        key="$(jq -r '.key' <<< "$field")"
        ask "$key" "$(jq -r '.label' <<< "$field")" "$(jq -r '.secret' <<< "$field")"
      done < <(jq -c '.fields[]' <<< "$row")
      [[ "$id" != mongo ]] || build_mongo_uri
    done
    log "OK: $id TCP/authenticated probe ($((SECONDS-started))s)"
  done < <(jq -c '.services[]' "$manifest")
}
ci_gate() {
  local commit="$1" checks name state conclusion found required
  if (( skip_ci )) || [[ -z "${answers[CI_CHECKS]}" ]]; then log 'SKIP: optional CI gate'; return; fi
  [[ -n "${answers[CI_REPOSITORY]}" ]] || die 'CI_REPOSITORY is required with --ci-check.'
  checks="$(gh api --paginate "repos/${answers[CI_REPOSITORY]}/commits/$commit/check-runs?per_page=100" --jq '.check_runs[] | [.name,.status,.conclusion] | @tsv')" || return 1
  if [[ "${answers[CI_CHECKS]}" == \[* ]]; then
    jq -e 'type=="array" and length>0 and all(.[]; type=="string" and length>0)' <<< "${answers[CI_CHECKS]}" >/dev/null || return 1
    mapfile -t required < <(jq -r '.[]' <<< "${answers[CI_CHECKS]}")
  else IFS=',' read -ra required <<< "${answers[CI_CHECKS]}"; fi
  for name in "${required[@]}"; do
    found=0
    while IFS=$'\t' read -r check state conclusion; do
      if [[ "$check" == "$name" ]]; then
        [[ "$state" == completed && "$conclusion" == success ]] || return 1
        found=1; break
      fi
    done <<< "$checks"
    (( found )) || return 1
  done
}
backup_gate() {
  local dump="${answers[BACKUP_FILE]}" contents
  [[ "${answers[BACKUP_RESTORE_VERIFIED]}" == 1 && -s "$dump" && ! -L "$dump" ]] || return 1
  [[ -n "$(find "$dump" -mmin -60 -print)" ]] || return 1
  contents="$(pg_restore --list "$dump")" || return 1
  grep -Fxq ";     dbname: ${answers[DB_DATABASE]}" <<< "$contents"
}
write_env() {
  local key value temp="$base/shared/.env-$$"
  (umask 077; : > "$temp")
  # Preserve keys outside this manifest without evaluating any shell expressions.
  if [[ -f "$base/shared/.env" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
      key="${line%%=*}"
      if [[ ! -v "allowed[$key]" ]]; then printf '%s\n' "$line" >> "$temp"; fi
    done < "$base/shared/.env"
  fi
  while IFS= read -r key; do
    value="${answers[$key]}"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\\$}"
    printf '%s="%s"\n' "$key" "$value" >> "$temp"
  done < <(jq -r '.services[].fields[] | select(.setting == null or .env == true) | .key' "$manifest")
  chmod 640 "$temp"
  mv -f "$temp" "$base/shared/.env"
}
control() {
  if (( no_systemd )); then log "UNIT: $*"; else sudo -n /usr/bin/systemctl "$@"; fi
}
role_units() {
  local prefix="${answers[UNIT_PREFIX]}"
  answers[STOP_UNITS]="$prefix-horizon $prefix-embedder"
  answers[RESTART_UNITS]="$prefix-embedder $prefix-horizon"
  answers[START_UNITS]=''
  if [[ "${answers[NODE_ROLE]}" == primary ]]; then
    answers[STOP_UNITS]="$prefix-scheduler ${answers[STOP_UNITS]}"
    answers[RESTART_UNITS]="${answers[FPM_UNIT]} ${answers[NGINX_UNIT]} ${answers[RESTART_UNITS]}"
    if (( start_scheduler )); then answers[START_UNITS]="$prefix-scheduler"; fi
  fi
  answers[ROLLBACK_UNITS]="${answers[RESTART_UNITS]} ${answers[START_UNITS]}"
  answers[FIRST_FAILURE_UNITS]="${answers[STOP_UNITS]}"
}
unit_action() {
  local action="$1" list="$2" units
  read -ra units <<< "$list"
  (( ${#units[@]} )) || return 0
  local unit
  for unit in "${units[@]}"; do control "$action" "$unit"; done
}
rollback() {
  local status=$?
  trap - ERR INT TERM
  set +e
  log "FAILED: release ($((SECONDS-started))s); restoring previous code and env"
  if [[ -n "$previous" ]]; then
    ln -s "$previous" "$base/.rollback-$$" && mv -Tf "$base/.rollback-$$" "$base/current"
  else rm -f "$base/current"; fi
  if [[ -f "$base/shared/.env-before-$$" ]]; then mv -f "$base/shared/.env-before-$$" "$base/shared/.env"; else rm -f "$base/shared/.env"; fi
  if (( quiesced )); then
    if [[ -n "$previous" ]]; then unit_action restart "${answers[ROLLBACK_UNITS]}"; else unit_action stop "${answers[FIRST_FAILURE_UNITS]}"; fi
  fi
  log 'Database migrations are NOT reversed; restore requires operator approval.'
  exit "$status"
}
readiness() {
  local deadline=$((SECONDS+answers[READINESS_TIMEOUT])) remaining unit healthy
  local units
  read -ra units <<< "${answers[RESTART_UNITS]} ${answers[START_UNITS]}"
  while (( SECONDS < deadline )); do
    healthy=1
    for unit in "${units[@]}"; do
      remaining=$((deadline-SECONDS))
      (( remaining > 0 )) || return 1
      control is-active "$unit" >/dev/null 2>&1 || healthy=0
    done
    remaining=$((deadline-SECONDS))
    (( remaining > 0 )) || return 1
    curl --silent --fail --max-time "$remaining" "${answers[EMBEDDER_URL]%/}/healthz" >/dev/null 2>&1 || healthy=0
    if [[ "${answers[NODE_ROLE]}" == primary ]]; then
      remaining=$((deadline-SECONDS))
      (( remaining > 0 )) || return 1
      curl --silent --fail --max-time "$remaining" "${answers[APP_URL]%/}/up" >/dev/null 2>&1 || healthy=0
    fi
    (( ! healthy )) || return 0
    sleep 1
  done
  return 1
}
apply_release() {
  base="${answers[TARGET_DIR]}"
  [[ "$(id -un 2>/dev/null || id -u)" == "${answers[SERVICE_USER]}" || "$(id -u)" == "${answers[SERVICE_USER]}" ]] || die 'Apply must run as SERVICE_USER.'
  if (( skip_backup )); then log 'WARNING: BACKUP CHECK BYPASSED; migration recovery is operator responsibility.';
  else step 'Fresh backup and restore attestation' backup_gate; fi
  mkdir -p "$base/shared/storage" "$base/releases"
  exec 9>"$base/shared/deploy.lock"
  flock -n 9 || die 'Another deployment is in progress.'
  local actual_digest='absent'
  if [[ -f "$base/shared/.env" ]]; then actual_digest="$(sha256sum "$base/shared/.env")"; fi
  [[ "$actual_digest" == "$env_digest" ]] || die 'Environment changed while collecting answers; rerun the wizard.'
  previous="$(readlink -f "$base/current" 2>/dev/null || true)"
  [[ -d "$previous" ]] || previous=""
  release="$(mktemp -d "$base/releases/release.XXXXXXXX")"
  quiesced=0
  [[ ! -f "$base/shared/.env" ]] || cp -p "$base/shared/.env" "$base/shared/.env-before-$$"
  trap rollback ERR
  trap 'false' INT TERM
  step 'Clone selected repository' git clone --no-checkout -- "${answers[REPO_URL]}" "$release/source"
  step 'Fetch selected branch, tag or SHA' git -C "$release/source" fetch --no-tags origin "${answers[REF]}"
  commit="$(git -C "$release/source" rev-parse --verify 'FETCH_HEAD^{commit}')"
  step 'Optional exact-commit CI gate' ci_gate "$commit"
  step 'Checkout verified commit' git -C "$release/source" checkout --detach "$commit"
  local named="$base/releases/$(date -u +%Y%m%dT%H%M%SZ)-${commit:0:7}-$$"
  mv "$release/source" "$named"
  rmdir "$release"
  release="$named"
  cd "$release" || return
  # Composer scripts boot Laravel: a private env must already be available.
  if [[ -z "${answers[APP_KEY]}" ]]; then answers[APP_KEY]="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"; fi
  step 'Write private environment (0640)' write_env
  ln -s "$base/shared/.env" .env
  rm -rf storage
  for folder in app/private app/public framework/cache/data framework/sessions framework/views logs; do mkdir -p "$base/shared/storage/$folder"; done
  ln -s "$base/shared/storage" storage
  step 'Install authoritative production dependencies' composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist
  step 'UI render smoke' node ui-tests/render-smoke.mjs
  quiesced=0
  if (( ! no_start )); then quiesced=1; step 'Quiesce scheduler and workers' unit_action stop "${answers[STOP_UNITS]}"; fi
  if [[ "${answers[NODE_ROLE]}" == primary ]]; then
    step 'Forward migrations' php artisan migrate --force
    # Only the primary initializes shared data; adding workers must not rotate its owner.
    step 'Import manifest database settings' import_settings
    step 'Import missing database settings' php artisan settings:import-env
    step 'Create Elasticsearch mappings before crawl' php artisan es:setup
    step 'Synchronize crawler sources' php artisan crawler:sync-sources
    if [[ -z "$previous" ]]; then
      step 'Create owner and private credentials file' owner_credentials
    else
      log 'SKIP: owner credentials; the existing account and password remain unchanged.'
    fi
  else log 'SKIP: worker uses the primary shared env/database; no migrations, shared setup or owner rotation.'; fi
  step 'Clear config/routes/views only' php artisan optimize:clear --except=cache
  ln -s "$release" "$base/.current-$$"
  mv -Tf "$base/.current-$$" "$base/current"
  if (( ! no_start )); then
    step 'Enable role units' unit_action enable "${answers[RESTART_UNITS]} ${answers[START_UNITS]}"
    step 'Restart application units' unit_action restart "${answers[RESTART_UNITS]}"
    step 'Start scheduler last' unit_action start "${answers[START_UNITS]}"
    step 'Readiness (role units and HTTP health)' readiness
  else log 'SKIP: --no-start; process readiness is not claimed.'; fi
  trap - ERR INT TERM
  rm -f "$base/shared/.env-before-$$"
  log "Next: cd $base/current && php artisan staging:readiness"
  if [[ "${answers[NODE_ROLE]}" == primary ]] && (( ! start_scheduler )); then log "Scheduler deliberately stopped; next: sudo systemctl start ${answers[UNIT_PREFIX]}-scheduler"; fi
  log "Deployed $commit; total $((SECONDS-started))s; current symlink verified."
}
import_settings() {
  local key
  for key in "${!answers[@]}"; do export "$key=${answers[$key]}"; done
  WIZARD_REPLACE_SETTINGS="$replace_existing" php "$root/deploy/lib/import-settings.php" "$manifest"
}
install_main() {
  exec 3<&0
  manifest="$root/deploy/manifest.json"
  config='' non_interactive=0 apply=0 skip_ci=1 replace_existing=0 validate_only=0 started=$SECONDS
  skip_os=0 no_systemd=0 no_start=0 start_scheduler=0 skip_backup=0
  declare -gA answers=() flags=() allowed=()
  while (( $# )); do
    case "$1" in
      --config|--ref|--repo|--target|--service-user|--role|--set)
        [[ $# -ge 2 ]] || die "Missing argument: $1"
        case "$1" in
          --config) config="$2" ;; --ref) flags[REF]="$2" ;; --repo) flags[REPO_URL]="$2" ;;
          --target) flags[TARGET_DIR]="$2" ;; --service-user) flags[SERVICE_USER]="$2" ;; --role) flags[NODE_ROLE]="$2" ;;
          --set) [[ "$2" == *=* ]] || die '--set requires KEY=VALUE'; flags[${2%%=*}]="${2#*=}" ;;
        esac
        shift 2 ;;
      --skip-os-install) skip_os=1; flags[INSTALL_NODE]=0; shift ;;
      --no-systemd) no_systemd=1; shift ;; --no-start) no_start=1; shift ;;
      --start-scheduler) start_scheduler=1; shift ;; --skip-backup-check) skip_backup=1; shift ;;
      --apply) apply=1; shift ;; --dry-run) apply=0; shift ;; --non-interactive) non_interactive=1; shift ;;
      --ci-check)
        skip_ci=0; shift
        if (( $# )) && [[ "$1" != --* ]]; then flags[CI_CHECKS]="$1"; shift; fi ;;
      --skip-ci-check) skip_ci=1; shift ;; --replace-existing) replace_existing=1; shift ;; --validate-only) validate_only=1; shift ;;
      --help|-h) log 'install.sh [--config private.json] [--ref branch|tag|SHA] [--repo URL] [--target DIR] [--non-interactive] [--apply] [--ci-check names|--skip-ci-check] [--set KEY=VALUE] [--replace-existing] [--validate-only] [--role primary|worker] [--skip-os-install] [--no-systemd] [--no-start] [--start-scheduler] [--skip-backup-check]'; return ;;
      *) die "Unknown argument: $1" ;;
    esac
  done
  command -v jq >/dev/null || die 'Install jq before running the wizard.'
  # Flags never accept secret fields: private config, environment or hidden prompts only.
  local key
  for key in "${!flags[@]}"; do
    if jq -e --arg k "$key" '.services[].fields[] | select(.key==$k and .secret)' "$manifest" >/dev/null; then die "Secret $key must come from private config, environment or hidden prompt."; fi
  done
  collect_answers
  role_units
  if (( ! apply )); then
    log 'DRY RUN: answers validated; no network calls, files, packages or services changed.'
    log 'DRY RUN: prerequisites; authenticated service probes; backup; clone/fetch selected ref; CI; composer; env/storage; UI; migrate; settings; atomic current; restart; readiness; rollback on failure.'
    return
  fi
  prerequisites
  if [[ "$(id -un 2>/dev/null || id -u)" != "${answers[SERVICE_USER]}" && "$(id -u)" != "${answers[SERVICE_USER]}" ]]; then
    local preserved='' handoff=(--apply --non-interactive)
    (( ! skip_ci )) || handoff+=(--skip-ci-check)
    (( ! replace_existing )) || handoff+=(--replace-existing)
    (( ! validate_only )) || handoff+=(--validate-only)
    (( ! skip_os )) || handoff+=(--skip-os-install)
    (( ! no_systemd )) || handoff+=(--no-systemd)
    (( ! no_start )) || handoff+=(--no-start)
    (( ! start_scheduler )) || handoff+=(--start-scheduler)
    (( ! skip_backup )) || handoff+=(--skip-backup-check)
    answers[INSTALL_NODE]=0
    (
      for key in "${!answers[@]}"; do
        export "$key=${answers[$key]}"
        preserved+="${preserved:+,}$key"
      done
      sudo "--preserve-env=$preserved" -u "${answers[SERVICE_USER]}" -- bash "$root/deploy/install.sh" "${handoff[@]}"
    )
    return
  fi
  (( EUID != 0 )) || die 'Composer and application steps must run as an unprivileged service user.'
  if [[ "${answers[INSTALL_CA]}" == 1 && -n "${answers[ELASTICSEARCH_CA]}" ]]; then
    (( ! skip_os )) || die 'CA installation conflicts with --skip-os-install.'
    local ca_target
    ca_target="$(jq -r '.node.ca_directory' "$manifest")/${answers[UNIT_PREFIX]}.crt"
    step 'Install operator supplied CA' sudo install -m 644 "${answers[ELASTICSEARCH_CA]}" "$ca_target"
    step 'Refresh OS trust' sudo update-ca-certificates
  fi
  build_mongo_uri
  validate_services
  (( ! validate_only )) || return 0
  apply_release
}
build_mongo_uri() {
  if [[ -n "${answers[MONGO_PASSWORD]}" ]]; then
    answers[MONGO_URI]="$(
      export MONGO_USERNAME="${answers[MONGO_USERNAME]}" MONGO_PASSWORD="${answers[MONGO_PASSWORD]}" MONGO_HOST="${answers[MONGO_HOST]}" MONGO_PORT="${answers[MONGO_PORT]}" MONGO_DATABASE="${answers[MONGO_DATABASE]}" MONGO_AUTH_SOURCE="${answers[MONGO_AUTH_SOURCE]}"
      php -r 'echo "mongodb://".rawurlencode(getenv("MONGO_USERNAME")).":".rawurlencode(getenv("MONGO_PASSWORD"))."@".getenv("MONGO_HOST").":".getenv("MONGO_PORT")."/".rawurlencode(getenv("MONGO_DATABASE"))."?authSource=".rawurlencode(getenv("MONGO_AUTH_SOURCE"));'
    )"
  fi
}
owner_credentials() {
  local capture password destination="${answers[CREDENTIALS_FILE]}" key temp
  capture="$(mktemp "$base/shared/owner.XXXXXXXX")"
  chmod 600 "$capture"
  if ! php artisan operations:make-super-admin "${answers[OWNER_EMAIL]}" --rotate > "$capture" 2>&1; then
    shred -u "$capture"; return 1
  fi
  password="$(awk 'NF {last=$0} END {print last}' "$capture")"
  shred -u "$capture"
  [[ -n "$password" ]] || return 1
  temp="$(mktemp "${destination}.XXXXXXXX")"
  chmod 600 "$temp"
  for key in "${!answers[@]}"; do printf '%s=%s\n' "$key" "${answers[$key]}" >> "$temp"; done
  printf 'OWNER_PASSWORD=%s\n' "$password" >> "$temp"
  mv -fT "$temp" "$destination"
}

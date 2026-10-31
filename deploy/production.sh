#!/usr/bin/env bash
# Operator-only native deployment of a commit from develop. Default: dry run.
# Usage: deploy/production.sh [--ref <sha>] [--apply] [--skip-ci-check]
set -Eeuo pipefail
umask 022
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
base="${XCRAWLER_ROOT:-/var/www/xcrawler}"
ref=origin/develop
explicit_ref=0
apply=0
skip_ci=0
die() { echo "ERROR: $*" >&2; exit 1; }
while (( $# )); do
  case "$1" in
    --ref) [[ $# -ge 2 && "$2" =~ ^[0-9a-fA-F]{40}$ ]] || die '--ref requires a full commit SHA.'; ref="$2"; explicit_ref=1; shift 2 ;;
    --apply) apply=1; shift ;;
    --skip-ci-check) skip_ci=1; shift ;;
    *) die 'Usage: production.sh [--ref <sha>] [--apply] [--skip-ci-check]' ;;
  esac
done
systemctl() { sudo -n /usr/bin/systemctl "$@"; }
[[ -z "$(git -C "$root" status --porcelain)" ]] || die 'Refusing a dirty tree.'
run() { if (( apply )); then "$@"; else printf 'DRY RUN:'; printf ' %q' "$@"; printf '\n'; fi; }
# Dry runs use cached refs and change no files or services.
run git -C "$root" fetch origin "+refs/heads/develop:refs/remotes/origin/develop"
commit="$(git -C "$root" rev-parse --verify "$ref^{commit}")" || die 'Commit is unavailable.'
if (( explicit_ref )); then
  [[ "${commit,,}" == "${ref,,}" ]] || die 'Ref must identify a commit.'
fi
git -C "$root" merge-base --is-ancestor "$commit" origin/develop || die 'Commit is not an ancestor of origin/develop.'
if (( skip_ci )); then
  echo 'WARNING: --skip-ci-check — exact-commit CI gate BYPASSED.' >&2
elif command -v gh >/dev/null 2>&1; then
  # Actions use check runs; legacy integrations may still publish commit statuses.
  if ! statuses="$(gh api "repos/jooservices/javmeta/commits/$commit/status" --jq '[.total_count, .state] | @tsv')" ||
     ! checks="$(gh api --paginate "repos/jooservices/javmeta/commits/$commit/check-runs?per_page=100" --jq '.check_runs[] | [.name, .status, .conclusion] | @tsv')"; then
    die 'WARNING: CI unavailable/offline; explicitly pass --skip-ci-check to proceed.'
  fi
  read -r status_count status_state <<< "$statuses"
  [[ "$status_count" =~ ^[0-9]+$ ]] || die 'Invalid CI status response.'
  if (( status_count > 0 )); then
    [[ "$status_state" == success ]] || die 'Commit CI status is not green.'
  fi
  [[ "$status_count" != 0 || -n "$checks" ]] || die 'Commit has no CI results.'
  required_checks=('Lint and test' 'Integration (Postgres, Mongo, Elasticsearch, OpenObserve)' 'E2E journeys (isolated services)' 'Secrets scan' 'Build app image')
  for required in "${required_checks[@]}"; do
    found=0
    while IFS=$'\t' read -r name state conclusion; do
      if [[ "$name" == "$required" ]]; then
        [[ "$state" == completed && "$conclusion" == success ]] || die "Required CI check is not green: $required"
        found=1
        break
      fi
    done <<< "$checks"
    (( found )) || die "Required CI check is missing: $required"
  done
  while IFS=$'\t' read -r name state conclusion; do
    [[ -z "$state" ]] && continue
    [[ "$state" == completed && "$conclusion" =~ ^(success|neutral|skipped)$ ]] || die 'Commit check runs are not green.'
  done <<< "$checks"
else
  die 'WARNING: gh is missing; explicitly pass --skip-ci-check to proceed.'
fi

release="$base/releases/$(date -u +%Y%m%dT%H%M%SZ)-${commit:0:7}"
previous="$(readlink -f "$base/current" 2>/dev/null || true)"
[[ "$base" == /* && "$base" != / ]] || die 'Invalid release root.'
[[ ! -e "$release" && ! -L "$release" ]] || die 'Release directory already exists; releases are immutable.'
if (( ! apply )); then
  echo "DRY RUN: archive $commit into $release; composer install --no-dev --classmap-authoritative"
  echo 'DRY RUN: link shared .env/storage; UI render smoke; validate backup; migrate --force; settings:import-env'
  echo 'DRY RUN: atomically switch current; restart Horizon/embedder/scheduler/php8.5-fpm; staging:readiness; rollback on failure'
  exit 0
fi
(( EUID != 0 )) || die 'Run apply as xcrawler, not root.'
[[ -s "$base/shared/.env" && -d "$base/shared/storage" ]] || die 'Private shared .env and storage are required.'
: "${XCRAWLER_BACKUP_FILE:?Set the freshly verified PostgreSQL custom-format backup path}"
[[ -s "$XCRAWLER_BACKUP_FILE" && ! -L "$XCRAWLER_BACKUP_FILE" ]] || die 'Backup missing or empty.'
[[ "$(find "$XCRAWLER_BACKUP_FILE" -mmin -60 -print)" ]] || die 'Backup must be less than one hour old.'
backup_contents="$(pg_restore --list "$XCRAWLER_BACKUP_FILE")" || die 'Backup is not readable by pg_restore.'
grep -Eq '^;[[:space:]]+dbname:[[:space:]]+xcrawler$' <<< "$backup_contents" || die 'Backup must be for database xcrawler.'
# Existence is insufficient: require evidence of an operator's restore rehearsal for this exact dump.
[[ "${XCRAWLER_BACKUP_RESTORE_VERIFIED:-0}" == 1 ]] || die 'Restore rehearsal acknowledgement is required.'
exec 9>"$base/shared/deploy.lock"
flock -n 9 || die 'Another deployment is in progress.'
[[ ! -e "$release" && ! -L "$release" ]] || die 'Release appeared while acquiring the deploy lock.'
previous="$(readlink -f "$base/current" 2>/dev/null || true)"
switched=0
quiesced=0
rollback() {
  local status=$?
  trap - ERR INT TERM
  echo "Deployment failed ($status); restoring the previous release." >&2
  if (( switched )) || [[ "$(readlink -f "$base/current" 2>/dev/null || true)" == "$release" ]]; then
    if [[ -n "$previous" ]]; then
      ln -s "$previous" "$base/.rollback-$$"
      mv -Tf "$base/.rollback-$$" "$base/current"
    else
      rm -f "$base/current"
    fi
  fi
  if (( quiesced )); then
    if [[ -n "$previous" ]]; then
      systemctl restart php8.5-fpm xcrawler-embedder xcrawler-horizon xcrawler-scheduler || true
    else
      systemctl stop xcrawler-horizon xcrawler-scheduler xcrawler-embedder || true
    fi
  fi
  echo 'Database migrations are NOT reversed. Use the rehearsed backup only with operator approval.' >&2
  exit "$status"
}
trap rollback ERR
trap 'false' INT TERM
mkdir -p "$release"
git -C "$root" archive "$commit" | tar -x -C "$release"
rm -rf "$release/storage"
ln -s "$base/shared/storage" "$release/storage"
ln -s "$base/shared/.env" "$release/.env"
cd "$release"
# Never execute Composer as root. The operator grants systemctl via a narrow sudo rule or runs this as xcrawler.
composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist
if [[ "${JAVMETA_SKIP_UI_SMOKE:-0}" == 1 ]]; then
  echo 'WARNING: JAVMETA_SKIP_UI_SMOKE=1 — UI render smoke gate BYPASSED.'
elif [[ -f ui-tests/render-smoke.mjs ]]; then
  node ui-tests/render-smoke.mjs
fi
# A stable venv lives in shared; update it explicitly before a release whose embedder requirements change.
[[ -x "$base/shared/embedder-venv/bin/uvicorn" ]] || die 'Install the native embedder venv first.'
quiesced=1
systemctl stop xcrawler-scheduler xcrawler-horizon
php artisan migrate --force
php artisan settings:import-env
# Native CLI and FPM share paths; clear only config/routes/views, never FLUSHDB under the scoped ACL.
php artisan optimize:clear --except=cache
ln -s "$release" "$base/.current-$$"
mv -Tf "$base/.current-$$" "$base/current"
switched=1
systemctl restart php8.5-fpm xcrawler-embedder xcrawler-horizon
systemctl start xcrawler-scheduler
if [[ "${JAVMETA_SKIP_READINESS:-0}" == 1 ]]; then
  echo 'WARNING: JAVMETA_SKIP_READINESS=1 — readiness gate BYPASSED.'
else
  deadline=$((SECONDS + 90))
  ready=0
  while (( SECONDS < deadline )); do
    remaining=$((deadline - SECONDS))
    output="$(timeout "${remaining}s" php artisan staging:readiness --no-record 2>&1)" || true
    if grep -q '^\[PASS\] horizon:' <<< "$output" && grep -q '^\[PASS\] scheduler:' <<< "$output"; then
      remaining=$((deadline - SECONDS))
      if (( remaining > 0 )) && output="$(timeout "${remaining}s" php artisan staging:readiness 2>&1)" && grep -qx 'Staging is READY' <<< "$output"; then
        ready=1; break
      fi
      break
    fi
    sleep 2
  done
  [[ "$ready" == 1 ]] || { printf '%s\n' "$output" >&2; echo 'Readiness failed.' >&2; false; }
fi
trap - ERR INT TERM
echo "Deployed $commit into $release."

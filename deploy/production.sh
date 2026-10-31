#!/usr/bin/env bash
# Operator-only native deployment of an annotated release on master. Default: dry run.
# Usage: deploy/production.sh v1.2.3 [--apply]
set -Eeuo pipefail
umask 022
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
base="${XCRAWLER_ROOT:-/var/www/xcrawler}"
tag="${1:-}"
apply=0
case "${2:-}" in '') ;; --apply) apply=1 ;; *) echo 'Usage: production.sh vX.Y.Z [--apply]' >&2; exit 2 ;; esac
[[ $# -le 2 && "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'An annotated vX.Y.Z release tag is required.' >&2; exit 1; }
die() { echo "ERROR: $*" >&2; exit 1; }
systemctl() { sudo -n /usr/bin/systemctl "$@"; }
[[ -z "$(git -C "$root" status --porcelain)" ]] || die 'Refusing a dirty tree.'
run() { if (( apply )); then "$@"; else printf 'DRY RUN:'; printf ' %q' "$@"; printf '\n'; fi; }
# Dry runs use cached refs and change no files or services. Apply fetches only the required refs.
run git -C "$root" fetch origin "+refs/heads/master:refs/remotes/origin/master" "refs/tags/$tag:refs/tags/$tag"
[[ "$(git -C "$root" cat-file -t "refs/tags/$tag" 2>/dev/null)" == tag ]] || die 'Refusing a branch, lightweight tag or missing tag.'
commit="$(git -C "$root" rev-parse "refs/tags/$tag^{commit}")"
git -C "$root" merge-base --is-ancestor "$commit" origin/master || die 'Tag is not on origin/master.'
release_metadata="$(gh release view "$tag" --repo jooservices/javmeta --json isDraft,isPrerelease,tagName)" || die 'No published GitHub release for this tag.'
jq -e --arg tag "$tag" '.tagName == $tag and .isDraft == false and .isPrerelease == false' <<< "$release_metadata" >/dev/null || die 'A published stable release is required.'

release="$base/releases/$tag"
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
echo "Deployed $tag ($commit)."

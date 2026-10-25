#!/usr/bin/env bash
# Shared by daily directory backups and staging quick-dump files. Never parse filenames as words.
validate_retention() {
  [[ "${1:-}" =~ ^[1-9][0-9]{0,8}$ ]] || { printf 'Retention must be a positive integer (at most 9 digits).\n' >&2; return 1; }
}

prune_backups() {
  local root="${1:-}" keep="${2:-}" kind="${3:-}" path
  local -a paths=()
  validate_retention "$keep" || return 1
  [[ -n "$root" && -d "$root" && "$root" != / ]] || return 1
  root="$(cd -- "$root" && pwd -P)" || return 1
  [[ "$root" != / ]] || return 1
  case "$kind" in
    directories) mapfile -d '' -t paths < <(find "$root" -mindepth 1 -maxdepth 1 -type d -print0 | sort -zr) ;;
    dumps) mapfile -d '' -t paths < <(find "$root" -mindepth 1 -maxdepth 1 -type f -name '*.dump' -print0 | sort -zr) ;;
    *) return 1 ;;
  esac
  # Verify the entire list before deleting anything; only direct children can be pruned.
  for path in "${paths[@]}"; do
    [[ -n "$path" && "${path%/*}" == "$root" && "${path##*/}" != . && "${path##*/}" != .. ]] || return 1
  done
  for path in "${paths[@]:keep}"; do
    rm -rf -- "$path"
  done
}

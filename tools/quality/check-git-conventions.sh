#!/usr/bin/env bash
set -euo pipefail

readonly HEADER_RE='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([^()]+\))?!?: [A-Z][^[:cntrl:]]*$'

usage() {
  cat <<'USAGE'
Usage:
  check-git-conventions.sh title <title>
  check-git-conventions.sh commit-message <message-file>
  check-git-conventions.sh commits <base..head>

All checks use local input and local Git objects; no network access is made.
USAGE
}

fail() {
  printf 'ERROR: %s\n' "$1" >&2
  return 1
}

validate_header() {
  local header="$1"
  local kind="$2"
  local header_length
  local subject

  if [[ "$header" == *$'\r'* || "$header" == *$'\n'* ]]; then
    fail "$kind must be a single line."
    return 1
  fi

  if [[ ! "$header" =~ $HEADER_RE ]]; then
    fail "$kind must use an allowed Conventional Commit type with an optional scope and breaking marker, and an uppercase ASCII subject (for example: feat(ui): Add a check)."
    return 1
  fi

  if [[ "$kind" == "commit header" && "$header" == *. ]]; then
    fail "$kind subject must not end with a period."
    return 1
  fi

  if [[ "$kind" == "PR title" ]]; then
    subject="${header#*: }"
    if (( ${#subject} < 2 )); then
      fail "$kind subject must contain at least two characters, matching the PR title workflow."
      return 1
    fi
  fi

  if [[ "$kind" == "commit header" ]]; then
    header_length=${#header}
    if (( header_length < 10 || header_length > 120 )); then
      fail "commit header must be between 10 and 120 characters."
      return 1
    fi

    subject="${header#*: }"
    if (( ${#subject} > 100 )); then
      fail "commit subject must be at most 100 characters."
      return 1
    fi
  fi

  return 0
}

is_forbidden_trailer() {
  local token="${1,,}"

  [[ "$token" == *coauthor* || "$token" == *co-author* || "$token" == ai || "$token" == ai-* || "$token" == *generated* ]]
}

validate_commit_message() {
  local message="$1"
  local source="$2"
  local header="${message%%$'\n'*}"
  local body=""
  local line
  local token
  local trailers

  validate_header "$header" "commit header" || return 1

  if [[ "$message" == *$'\n\n'* ]]; then
    body="${message##*$'\n\n'}"
  fi

  while IFS= read -r line; do
    if [[ "$line" =~ ^([[:alnum:]][[:alnum:]-]*)(:|=)[[:space:]]* ]]; then
      token="${BASH_REMATCH[1]}"
      if is_forbidden_trailer "$token"; then
        fail "$source contains prohibited AI/coauthor trailer '$token'; remove the attribution trailer."
        return 1
      fi
    elif [[ "${line,,}" =~ ^[[:space:]]*generated[[:space:]-]+with[[:space:]]+.+$ ]]; then
      fail "$source contains prohibited AI/coauthor trailer 'Generated with'; remove the attribution trailer."
      return 1
    fi
  done <<< "$body"

  # Git parses the footer locally; project trailers such as Ticket: T16 pass.
  trailers="$(git interpret-trailers --parse <<< "$message" 2>/dev/null)" || {
    fail "$source trailer block could not be parsed by Git."
    return 1
  }

  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    token="${line%%:*}"
    if is_forbidden_trailer "$token"; then
      fail "$source contains prohibited AI/coauthor trailer '$token'; remove the attribution trailer."
      return 1
    fi
  done <<< "$trailers"
}

check_title() {
  validate_header "$1" "PR title"
}

check_commit_message_file() {
  local message_file="$1"
  local message

  if [[ ! -f "$message_file" || ! -r "$message_file" ]]; then
    fail "commit message file is not readable: $message_file"
    return 1
  fi

  message="$(<"$message_file")"
  validate_commit_message "$message" "commit message"
}

check_commit_range() {
  local range="$1"
  local commits
  local commit
  local message
  local checked=0
  local failed=0

  if [[ ! "$range" =~ ^[[:alnum:]_./^~:+-]+\.\.\.?[[:alnum:]_./^~:+-]+$ ]]; then
    fail "commit range must be a local Git range such as origin/develop..HEAD."
    return 1
  fi

  if ! commits="$(git rev-list --reverse --no-merges "$range" 2>/dev/null)"; then
    fail "could not resolve local commit range '$range'."
    return 1
  fi

  if [[ -z "$commits" ]]; then
    printf 'PASS: no non-merge commits in %s.\n' "$range"
    return 0
  fi

  while IFS= read -r commit; do
    [[ -z "$commit" ]] && continue
    if ! message="$(git show -s --format=%B "$commit" 2>/dev/null)"; then
      fail "could not read local commit ${commit:0:7}."
      failed=1
      continue
    fi
    checked=$((checked + 1))
    if ! validate_commit_message "$message" "commit ${commit:0:7}"; then
      failed=1
    fi
  done <<< "$commits"

  if (( failed != 0 )); then
    return 1
  fi

  printf 'PASS: checked %d commit(s) in %s.\n' "$checked" "$range"
}

main() {
  local command="${1:-}"
  shift || true

  case "$command" in
    title)
      [[ $# -eq 1 ]] || { usage >&2; return 2; }
      check_title "$1"
      ;;
    commit-message)
      [[ $# -eq 1 ]] || { usage >&2; return 2; }
      check_commit_message_file "$1"
      ;;
    commits)
      [[ $# -eq 1 ]] || { usage >&2; return 2; }
      check_commit_range "$1"
      ;;
    -h|--help|help)
      usage
      ;;
    *)
      usage >&2
      return 2
      ;;
  esac
}

main "$@"

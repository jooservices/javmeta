#!/usr/bin/env bash
set -euo pipefail

readonly HEADER_RE='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([^()]+\))?!?: [A-Z][^[:cntrl:]]*$'

usage() {
  cat <<'USAGE'
Usage:
  check-git-conventions.sh title <title>
  check-git-conventions.sh commit-message <message-file>
  check-git-conventions.sh commits <base..head>
  check-git-conventions.sh pre-push <remote> <ref-updates>

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
  local normalized
  local header
  local body=""
  local line
  local token
  local trailers

  normalized="$(git stripspace --strip-comments <<<"$message" 2>/dev/null)" || {
    fail "$source could not be normalized with Git cleanup rules."
    return 1
  }
  header="${normalized%%$'\n'*}"

  validate_header "$header" "commit header" || return 1

  if [[ "$normalized" == *$'\n'* ]]; then
    body="${normalized#*$'\n'}"
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

resolve_default_remote_base() {
  local remote="$1"
  local tracking_ref=""
  local candidate

  if git check-ref-format "refs/remotes/${remote}/HEAD" >/dev/null 2>&1; then
    tracking_ref="$(git symbolic-ref --quiet "refs/remotes/${remote}/HEAD" 2>/dev/null || true)"
  fi

  if [[ -z "$tracking_ref" ]] && git check-ref-format "refs/remotes/${remote}/develop" >/dev/null 2>&1; then
    tracking_ref="refs/remotes/${remote}/develop"
  fi

  for candidate in "$tracking_ref" refs/remotes/origin/develop; do
    [[ -n "$candidate" ]] || continue
    if git rev-parse --verify --quiet "${candidate}^{commit}"; then
      return 0
    fi
  done

  return 1
}

check_pre_push() {
  local remote="$1"
  local ref_updates="$2"
  local line
  local local_ref
  local local_sha
  local remote_ref
  local remote_sha
  local extra
  local base_sha
  local head_sha
  local checked=0
  local received=0

  while IFS= read -r line; do
    [[ -n "$line" ]] || continue
    received=$((received + 1))
    local_ref=""
    local_sha=""
    remote_ref=""
    remote_sha=""
    extra=""
    read -r local_ref local_sha remote_ref remote_sha extra <<<"$line"

    if [[ -z "$local_ref" || -z "$local_sha" || -z "$remote_ref" || -z "$remote_sha" || -n "$extra" ]]; then
      fail "pre-push received a malformed ref update."
      return 1
    fi
    if ! git check-ref-format "$remote_ref" >/dev/null 2>&1; then
      fail "pre-push received an invalid ref update."
      return 1
    fi

    if [[ "$local_sha" =~ ^0+$ ]]; then
      continue
    fi
    if [[ ! "$local_sha" =~ ^([[:xdigit:]]{40}|[[:xdigit:]]{64})$ ]]; then
      fail "pre-push received an invalid local object ID."
      return 1
    fi
    if ! head_sha="$(git rev-parse --verify --quiet "${local_sha}^{commit}")"; then
      fail "pre-push could not resolve the local commit."
      return 1
    fi

    if [[ "$remote_sha" =~ ^0+$ ]]; then
      if ! base_sha="$(resolve_default_remote_base "$remote")"; then
        fail "pre-push cannot check new ref '$remote_ref': no local remote default branch is available; update the remote-tracking default branch first."
        return 1
      fi
    else
      if [[ ! "$remote_sha" =~ ^([[:xdigit:]]{40}|[[:xdigit:]]{64})$ ]]; then
        fail "pre-push received an invalid remote object ID for '$remote_ref'."
        return 1
      fi
      if ! base_sha="$(git rev-parse --verify --quiet "${remote_sha}^{commit}")"; then
        fail "pre-push could not resolve the previous commit for '$remote_ref'."
        return 1
      fi
    fi

    check_commit_range "${base_sha}..${head_sha}" || return 1
    checked=$((checked + 1))
  done <<<"$ref_updates"

  if (( received == 0 )); then
    fail "pre-push received no ref updates to check."
    return 1
  fi

  if (( checked == 0 )); then
    printf 'PASS: no outgoing commits in %d deletion update(s).\n' "$received"
  else
    printf 'PASS: checked %d outgoing ref update(s).\n' "$checked"
  fi
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
    pre-push)
      [[ $# -eq 2 ]] || { usage >&2; return 2; }
      check_pre_push "$1" "$2"
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

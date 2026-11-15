#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
CHECKER="${ROOT}/tools/quality/check-git-conventions.sh"
gh_arguments=("$@")
title=""
title_seen=0

while (($#)); do
  case "$1" in
    --title|-t)
      if (($# < 2)); then
        printf 'ERROR: %s requires a title.\n' "$1" >&2
        exit 2
      fi
      title="$2"
      title_seen=1
      shift 2
      ;;
    --title=*|-t=*)
      title="${1#*=}"
      title_seen=1
      shift
      ;;
    -t?*)
      title="${1#-t}"
      title_seen=1
      shift
      ;;
    --assignee|-a|--attach|--base|-B|--body|-b|--body-file|-F|--head|-H|--label|-l|--milestone|-m|--project|-p|--recover|--repo|-R|--reviewer|-r|--template|-T)
      if (($# < 2)); then
        printf 'ERROR: %s requires a value.\n' "$1" >&2
        exit 2
      fi
      shift 2
      ;;
    --assignee=*|--attach=*|--base=*|--body=*|--body-file=*|--head=*|--label=*|--milestone=*|--project=*|--recover=*|--repo=*|--reviewer=*|--template=*|-a?*|-B?*|-b?*|-F?*|-H?*|-l?*|-m?*|-p?*|-R?*|-r?*|-T?*)
      shift
      ;;
    --)
      break
      ;;
    --editor|-e|--web|-w|--editor=*|--web=*|-e=*|-w=*)
      printf 'ERROR: PR creation wrapper cannot use %s because it allows the submitted title to change after validation.\n' "$1" >&2
      exit 2
      ;;
    *)
      shift
      ;;
  esac
done

if ((title_seen == 0)); then
  printf 'ERROR: PR creation requires an explicit title via --title or -t.\n' >&2
  exit 2
fi

bash "$CHECKER" title "$title"
exec gh pr create "${gh_arguments[@]}"

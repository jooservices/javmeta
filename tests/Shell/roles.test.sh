#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
source "$root/deploy/lib/install.sh"
declare -A answers=([UNIT_PREFIX]=test [NODE_ROLE]=worker [FPM_UNIT]=test-fpm [NGINX_UNIT]=test-nginx [EXPORTER_UNIT]=test-exporter)
start_scheduler=1
role_units
[[ "${answers[RESTART_UNITS]}" == 'test-embedder test-horizon test-exporter' ]]
[[ -z "${answers[START_UNITS]}" ]]
[[ "${answers[STOP_UNITS]}" != *scheduler* ]]
answers[NODE_ROLE]=primary
start_scheduler=0
role_units
[[ -z "${answers[START_UNITS]}" ]]
start_scheduler=1
role_units
[[ "${answers[START_UNITS]}" == test-scheduler ]]
echo 'OK (5 role/scheduler assertions)'
answers[MONGO_USERNAME]='name@node'
answers[MONGO_PASSWORD]='p@ss:/?#'
answers[MONGO_HOST]='database.test'
answers[MONGO_PORT]='27017'
answers[MONGO_DATABASE]='catalog'
answers[MONGO_AUTH_SOURCE]='admin'
if command -v php >/dev/null; then
  build_mongo_uri
  # shellcheck disable=SC2031 # The function assigns the parent array after its encoding subshell.
  [[ "${answers[MONGO_URI]}" == 'mongodb://name%40node:p%40ss%3A%2F%3F%23@database.test:27017/catalog?authSource=admin' ]]
  echo 'OK (1 Mongo URI encoding assertion)'
fi

skip_ci=0 replace_existing=0 validate_only=0 skip_os=1 no_systemd=1 no_start=1 start_scheduler=0 skip_backup=1
handoff=()
make_handoff_flags
[[ " ${handoff[*]} " == *' --ci-check '* ]]
[[ " ${handoff[*]} " != *' --skip-ci-check '* ]]
skip_ci=1
make_handoff_flags
[[ " ${handoff[*]} " == *' --skip-ci-check '* ]]
echo 'OK (3 service-user CI handoff assertions)'
